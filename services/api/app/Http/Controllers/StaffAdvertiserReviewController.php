<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\StaffResourceContext;
use App\Models\AdvertiserCampaign;
use App\Models\AdvertiserCampaignReviewFeedback;
use Illuminate\Support\Facades\DB;
use App\Models\StaffMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffAdvertiserReviewController extends Controller
{
    public function index(Request $request, int $organization): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'advertiser_organization_id' => ['missing'],
            'staff_member_id' => ['missing'],
            'status' => ['missing'],
        ]);

        return DB::transaction(function () use ($request, $organization, $data): JsonResponse {
            // Hold staff, bearer, organization and assignment locks until the
            // private response is serialized. A committed revocation cannot
            // race an already-authorized campaign read.
            $this->lockReviewAccess($request, $organization);

            // Only the assigned organization's submitted campaigns are visible.
            $query = AdvertiserCampaign::query()
                ->where('advertiser_organization_id', $organization)
                ->where('status', 'in_review');

            if (array_key_exists('before_id', $data)) {
                $query->where('id', '<', (int) $data['before_id']);
            }
            $limit = (int) ($data['limit'] ?? 20);
            $rows = $query->orderByDesc('id')->limit($limit + 1)
                ->lockForUpdate()
                ->get(['id', 'status', 'revision', 'updated_at']);
            $hasMore = $rows->count() > $limit;
            $page = $rows->take($limit)->map(static fn (AdvertiserCampaign $campaign): array => [
                'id' => $campaign->getKey(),
                'status' => $campaign->status,
                'revision' => $campaign->revision,
                'updated_at' => $campaign->updated_at?->toIso8601String(),
            ])->values()->all();

            return $this->privateResponse([
                'campaigns' => $page,
                'next_before_id' => $hasMore ? $page[count($page) - 1]['id'] : null,
            ]);
        }, 3);
    }

    public function show(Request $request, int $organization, int $campaign): JsonResponse
    {
        return DB::transaction(function () use ($request, $organization, $campaign): JsonResponse {
            [$staff, $token] = $this->lockReviewAccess($request, $organization);

            // Keep the organization and review state in the SQL predicate:
            // foreign and draft campaign IDs must remain undiscoverable.
            $record = AdvertiserCampaign::query()
                ->where('advertiser_organization_id', $organization)
                ->where('status', 'in_review')
                ->whereKey($campaign)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($staff)->authorize('staff-resource', [
                'advertising.campaign.read',
                new StaffResourceContext(
                    (int) $record->getKey(),
                    'advertiser_campaign',
                    $record->status,
                    advertiserId: (int) $record->advertiser_organization_id,
                ),
                $token,
            ]);

            return $this->privateResponse(['campaign' => [
                'id' => $record->getKey(),
                'title' => $record->title,
                'body' => $record->body,
                'status' => $record->status,
                'revision' => $record->revision,
            ]]);
        }, 3);
    }

    public function requestChanges(Request $request, int $organization, int $campaign): JsonResponse
    {
        [$staff, $token] = $this->staffSession($request);
        $this->assertReviewAssignment($staff, $token, $organization);

        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'feedback' => ['required', 'string', 'min:15', 'max:2000', 'not_regex:/[<>]/'],
            'status' => ['missing'],
            'advertiser_organization_id' => ['missing'],
            'advertiser_account_id' => ['missing'],
            'staff_member_id' => ['missing'],
            'approved_by' => ['missing'],
            'id' => ['missing'],
        ]);

        $result = DB::transaction(function () use ($request, $staff, $token, $organization, $campaign, $data): array {
            // Lock the persisted assignment before the campaign. Revocation
            // must not slip between authorization and the state mutation.
            $assignment = DB::table('staff_advertiser_assignments')
                ->where('staff_member_id', $staff->getKey())
                ->where('advertiser_organization_id', $organization)
                ->where('is_active', true)
                ->lockForUpdate()->first();
            if (! $assignment) {
                abort(404);
            }

            $record = AdvertiserCampaign::query()
                ->where('advertiser_organization_id', $organization)
                ->where('status', 'in_review')
                ->whereKey($campaign)->lockForUpdate()->firstOrFail();

            Gate::forUser($staff)->authorize('staff-resource', [
                'advertising.campaign.request_changes',
                new StaffResourceContext(
                    (int) $record->getKey(), 'advertiser_campaign', $record->status,
                    advertiserId: (int) $record->advertiser_organization_id,
                ),
                $token,
            ]);

            if ((int) $record->revision !== (int) $data['revision']) {
                abort(409, 'Campaign revision conflict.');
            }

            $nextRevision = (int) $record->revision + 1;
            $changed = AdvertiserCampaign::query()
                ->whereKey($record->getKey())
                ->where('advertiser_organization_id', $organization)
                ->where('status', 'in_review')
                ->where('revision', $data['revision'])
                ->update(['status' => 'draft', 'revision' => $nextRevision, 'updated_at' => now()]);
            if ($changed !== 1) {
                abort(409, 'Campaign revision conflict.');
            }

            // The encrypted feedback and state transition commit atomically.
            // Reviewer identity is retained only in the append-only ledger.
            $feedback = AdvertiserCampaignReviewFeedback::query()->create([
                'advertiser_organization_id' => $organization,
                'advertiser_campaign_id' => $record->getKey(),
                'staff_member_id' => $staff->getKey(),
                'action' => 'changes_requested',
                'feedback' => $data['feedback'],
                'from_revision' => (int) $data['revision'],
                'to_revision' => $nextRevision,
                'request_id' => $request->attributes->get('request_id'),
                'created_at' => now(),
            ]);

            return ['campaign' => [
                'id' => $record->getKey(),
                'status' => 'draft',
                'revision' => $nextRevision,
            ], 'feedback_id' => $feedback->getKey()];
        });

        // Requesting changes is not approval, delivery or publication.
        return $this->privateResponse($result);
    }

    /**
     * Lock order: staff -> bearer -> organization -> assignment -> campaign.
     * Revocation, deactivation and review-state transitions cannot commit
     * between the persisted authorization check and private serialization.
     *
     * @return array{StaffMember, PersonalAccessToken}
     */
    private function lockReviewAccess(Request $request, int $organization): array
    {
        [$staff, $token] = $this->staffSession($request);

        $actor = StaffMember::query()->whereKey($staff->getKey())
            ->lockForUpdate()->first();
        $currentToken = PersonalAccessToken::query()->whereKey($token->getKey())
            ->lockForUpdate()->first();

        if (! $actor || ! $currentToken
            || $currentToken->expires_at === null || $currentToken->expires_at->isPast()
            || $currentToken->tokenable_type !== $actor->getMorphClass()
            || (string) $currentToken->tokenable_id !== (string) $actor->getKey()) {
            abort(401, 'Unauthenticated.');
        }

        $advertiser = DB::table('advertiser_organizations')
            ->where('id', $organization)->lockForUpdate()->first();
        if (! $advertiser || ! $advertiser->is_active) {
            abort(404);
        }

        $assignment = DB::table('staff_advertiser_assignments')
            ->where('staff_member_id', $actor->getKey())
            ->where('advertiser_organization_id', $organization)
            ->lockForUpdate()->first();
        if (! $assignment || ! $assignment->is_active) {
            abort(404);
        }

        $this->assertReviewAssignment($actor, $currentToken, $organization);

        return [$actor, $currentToken];
    }

    private function assertReviewAssignment(StaffMember $staff, PersonalAccessToken $token, int $organization): void
    {
        if (! Gate::forUser($staff)->allows('staff-resource', [
            'advertising.organization.review',
            new StaffResourceContext($organization, 'advertiser_organization', 'active', advertiserId: $organization),
            $token,
        ])) {
            abort(404);
        }
    }

    /** @return array{StaffMember, PersonalAccessToken} */
    private function staffSession(Request $request): array
    {
        $staff = $request->user();
        $raw = $request->bearerToken();
        $token = $raw ? PersonalAccessToken::findToken($raw) : null;

        // Never authorize using a cached guard user or token from another identity.
        if (! $staff instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || $token->tokenable_type !== $staff->getMorphClass()
            || (string) $token->tokenable_id !== (string) $staff->getKey()) {
            abort(403);
        }

        return [$staff, $token];
    }

    private function privateResponse(array $data): JsonResponse
    {
        return response()->json($data)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
