<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserCampaign;
use App\Models\AdvertiserCampaignReviewFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdvertiserCampaignController extends Controller
{
    public function organizations(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $organizations = DB::table('advertiser_organizations as o')
            ->join('advertiser_memberships as m', 'm.advertiser_organization_id', '=', 'o.id')
            ->where('m.advertiser_account_id', $account->getKey())
            ->where('m.is_active', true)
            ->where('o.is_active', true)
            ->orderBy('o.id')
            ->select(['o.id', 'o.name', 'm.role'])
            ->get();

        return $this->privateResponse(['organizations' => $organizations]);
    }

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->membership($this->account($request), $organization);

        $campaigns = AdvertiserCampaign::query()
            ->where('advertiser_organization_id', $organization)
            ->orderByDesc('id')
            ->paginate(20);

        return $this->privateResponse([
            'campaigns' => $campaigns->getCollection()->map(
                fn (AdvertiserCampaign $campaign): array => $this->payload($campaign, false)
            )->all(),
            'current_page' => $campaigns->currentPage(),
            'last_page' => $campaigns->lastPage(),
        ]);
    }

    public function show(Request $request, int $organization, int $campaign): JsonResponse
    {
        $this->membership($this->account($request), $organization);
        $record = $this->scopedCampaign($organization, $campaign);

        return $this->privateResponse(['campaign' => $this->payload($record)]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $account = $this->account($request);

        $campaign = DB::transaction(function () use ($account, $organization, $data): AdvertiserCampaign {
            $this->membership($account, $organization, true, true);

            return AdvertiserCampaign::query()->create([
                'advertiser_organization_id' => $organization,
                'title' => $data['title'],
                'body' => $data['body'],
            ]);
        });

        return $this->privateResponse(['campaign' => $this->payload($campaign)], 201);
    }

    public function update(Request $request, int $organization, int $campaign): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        if (! array_key_exists('title', $data) && ! array_key_exists('body', $data)) {
            throw ValidationException::withMessages(['body' => ['At least one campaign field is required.']]);
        }

        $account = $this->account($request);
        $record = DB::transaction(function () use ($account, $organization, $campaign, $data): AdvertiserCampaign {
            $this->membership($account, $organization, true, true);
            $record = $this->scopedCampaign($organization, $campaign, true);
            if ($record->status !== 'draft') {
                abort(403, 'Campaign is not editable.');
            }
            if ((int) $record->revision !== (int) $data['revision']) {
                abort(409, 'Campaign revision conflict.');
            }

            $record->forceFill([
                ...array_intersect_key($data, array_flip(['title', 'body'])),
                'revision' => (int) $record->revision + 1,
            ])->save();

            return $record;
        });

        return $this->privateResponse(['campaign' => $this->payload($record)]);
    }

    public function submit(Request $request, int $organization, int $campaign): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'title' => ['missing'],
            'body' => ['missing'],
            'status' => ['missing'],
            'advertiser_organization_id' => ['missing'],
            'advertiser_account_id' => ['missing'],
            'reviewer_id' => ['missing'],
            'approved_by' => ['missing'],
            'id' => ['missing'],
        ]);

        $account = $this->account($request);

        $record = DB::transaction(function () use ($request, $account, $organization, $campaign, $data): AdvertiserCampaign {
            // Keep lock acquisition order consistent with store/update:
            // membership first, then the organization-scoped campaign.
            $this->membership($account, $organization, true, true);
            $record = $this->scopedCampaign($organization, $campaign, true);

            if ($record->status !== 'draft') {
                abort(409, 'Campaign is already submitted for review.');
            }
            if ((int) $record->revision !== (int) $data['revision']) {
                abort(409, 'Campaign revision conflict.');
            }

            // Database-level compare-and-swap protects against stale
            // concurrent submissions even if future code changes row locks.
            $nextRevision = (int) $record->revision + 1;
            $changed = AdvertiserCampaign::query()
                ->whereKey($record->getKey())
                ->where('advertiser_organization_id', $organization)
                ->where('status', 'draft')
                ->where('revision', $data['revision'])
                ->update([
                    'status' => 'in_review',
                    'revision' => $nextRevision,
                    'updated_at' => now(),
                ]);
            if ($changed !== 1) {
                abort(409, 'Campaign revision conflict.');
            }

            // Audit and state transition commit or roll back together.
            // Request UUID originates exclusively from AttachRequestId.
            DB::table('advertiser_campaign_review_events')->insert([
                'advertiser_organization_id' => $organization,
                'advertiser_campaign_id' => $record->getKey(),
                'advertiser_account_id' => $account->getKey(),
                'action' => 'submitted_for_review',
                'from_revision' => (int) $data['revision'],
                'to_revision' => $nextRevision,
                'request_id' => $request->attributes->get('request_id'),
                'created_at' => now(),
            ]);

            return $record->fresh();
        });

        // This endpoint never approves, publishes, bills or serves advertising.
        return $this->privateResponse(['campaign' => $this->payload($record)]);
    }

    public function reviewFeedback(Request $request, int $organization, int $campaign): JsonResponse
    {
        $account = $this->account($request);
        $this->membership($account, $organization, true);
        $this->scopedCampaign($organization, $campaign);

        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'advertiser_organization_id' => ['missing'],
            'advertiser_account_id' => ['missing'],
            'staff_member_id' => ['missing'],
        ]);

        $limit = (int) ($data['limit'] ?? 20);
        $query = AdvertiserCampaignReviewFeedback::query()
            ->where('advertiser_organization_id', $organization)
            ->where('advertiser_campaign_id', $campaign);
        if (array_key_exists('before_id', $data)) {
            $query->where('id', '<', (int) $data['before_id']);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $events = $rows->take($limit)->map(static fn (AdvertiserCampaignReviewFeedback $event): array => [
            'id' => $event->getKey(),
            'action' => $event->action,
            'feedback' => $event->feedback,
            'from_revision' => $event->from_revision,
            'to_revision' => $event->to_revision,
            'created_at' => $event->created_at?->toIso8601String(),
        ])->values()->all();

        return $this->privateResponse([
            'events' => $events,
            'next_before_id' => $hasMore ? $events[count($events) - 1]['id'] : null,
        ]);
    }

    private function account(Request $request): AdvertiserAccount
    {
        $account = $request->user();
        if (! $account instanceof AdvertiserAccount) {
            abort(403);
        }

        return $account;
    }

    private function membership(AdvertiserAccount $account, int $organization, bool $edit = false, bool $lock = false): object
    {
        // Membership and organization activation are persisted and checked for
        // every request. Never grant access on the basis of a URL or JWT claim.
        $query = DB::table('advertiser_memberships as m')
            ->join('advertiser_organizations as o', 'o.id', '=', 'm.advertiser_organization_id')
            ->where('m.advertiser_account_id', $account->getKey())
            ->where('m.advertiser_organization_id', $organization)
            ->where('m.is_active', true)
            ->where('o.is_active', true)
            ->select(['m.id', 'm.role']);

        $membership = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $membership) {
            abort(404);
        }
        if ($edit && ! in_array($membership->role, ['owner', 'editor'], true)) {
            abort(403);
        }

        return $membership;
    }

    private function scopedCampaign(int $organization, int $campaign, bool $lock = false): AdvertiserCampaign
    {
        $query = AdvertiserCampaign::query()
            ->where('advertiser_organization_id', $organization)
            ->whereKey($campaign);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function rules(bool $creating): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:5', 'max:180'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'min:20', 'max:12000'],
            'revision' => $creating ? ['missing'] : ['required', 'integer', 'min:1'],
            'advertiser_organization_id' => ['missing'],
            'advertiser_account_id' => ['missing'],
            'status' => ['missing'],
            'id' => ['missing'],
        ];
    }

    private function payload(AdvertiserCampaign $campaign, bool $includeBody = true): array
    {
        $data = [
            'id' => $campaign->getKey(),
            'title' => $campaign->title,
            'status' => $campaign->status,
            'revision' => $campaign->revision,
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
        if ($includeBody) {
            $data['body'] = $campaign->body;
        }

        return $data;
    }

    private function privateResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
