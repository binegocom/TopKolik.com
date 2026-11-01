<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\StaffResourceContext;
use App\Models\AdvertiserCampaign;
use App\Models\StaffMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffAdvertiserReviewController extends Controller
{
    public function index(Request $request, int $organization): JsonResponse
    {
        [$staff, $token] = $this->staffSession($request);
        $this->assertReviewAssignment($staff, $token, $organization);

        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'advertiser_organization_id' => ['missing'],
            'staff_member_id' => ['missing'],
            'status' => ['missing'],
        ]);

        // Scoping is mandatory at the SQL boundary, not client-supplied.
        // Only submitted campaigns may be seen, never advertiser drafts.
        $query = AdvertiserCampaign::query()
            ->where('advertiser_organization_id', $organization)
            ->where('status', 'in_review');

        if (array_key_exists('before_id', $data)) {
            $query->where('id', '<', (int) $data['before_id']);
        }
        $limit = (int) ($data['limit'] ?? 20);
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
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
    }

    public function show(Request $request, int $organization, int $campaign): JsonResponse
    {
        [$staff, $token] = $this->staffSession($request);
        $this->assertReviewAssignment($staff, $token, $organization);

        // The advertiser's organization ID is enforced before finding a
        // resource, so foreign campaign IDs cannot disclose cross-tenant data.
        $record = AdvertiserCampaign::query()
            ->where('advertiser_organization_id', $organization)
            ->where('status', 'in_review')
            ->whereKey($campaign)
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
