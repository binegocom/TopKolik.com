<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\StaffResourceContext;
use App\Models\EditorialArticle;
use App\Models\StaffMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffEditorialDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var StaffMember $staff */
        $staff = $request->user();
        Gate::forUser($staff)->authorize('staff-create-draft', [$this->token($request)]);

        // This query never considers another editor's records.
        $drafts = EditorialArticle::query()
            ->where('owner_staff_id', $staff->getKey())
            ->whereIn('status', ['draft', 'in_review', 'held'])
            ->orderByDesc('id')->paginate(20);

        return $this->privateResponse([
            'drafts' => $drafts->getCollection()
                ->map(fn (EditorialArticle $draft) => $this->payload($draft, false))->all(),
            'current_page' => $drafts->currentPage(),
            'last_page' => $drafts->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'body' => ['required', 'string', 'min:20', 'max:30000'],
            'owner_staff_id' => ['missing'],
            'status' => ['missing'],
            'revision' => ['missing'],
            'submitted_at' => ['missing'],
            'id' => ['missing'],
        ]);
        /** @var StaffMember $staff */
        $staff = $request->user();
        Gate::forUser($staff)->authorize('staff-create-draft', [$this->token($request)]);

        $draft = EditorialArticle::query()->create([
            'owner_staff_id' => $staff->getKey(),
            'title' => $data['title'],
            'body' => $data['body'],
        ]);

        return $this->privateResponse(['draft' => $this->payload($draft)], 201);
    }

    public function show(Request $request, int $draft): JsonResponse
    {
        /** @var StaffMember $staff */
        $staff = $request->user();
        $record = $this->scopedRecord($staff, $draft);
        $this->authorizeDraft($staff, $record, 'editorial.draft.view', $this->token($request));

        return $this->privateResponse(['draft' => $this->payload($record)]);
    }

    public function update(Request $request, int $draft): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:5', 'max:180'],
            'body' => ['sometimes', 'required', 'string', 'min:20', 'max:30000'],
            'revision' => ['required', 'integer', 'min:1'],
            'owner_staff_id' => ['missing'],
            'status' => ['missing'],
            'submitted_at' => ['missing'],
            'id' => ['missing'],
        ]);
        if (! array_key_exists('title', $data) && ! array_key_exists('body', $data)) {
            throw ValidationException::withMessages(['body' => ['At least one draft field is required.']]);
        }
        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->token($request);

        $record = DB::transaction(function () use ($staff, $token, $draft, $data): EditorialArticle {
            $record = $this->scopedRecord($staff, $draft, true);
            $this->authorizeDraft($staff, $record, 'editorial.draft.edit', $token);
            if ((int) $record->revision !== (int) $data['revision']) {
                abort(409, 'Draft revision conflict.');
            }

            $record->forceFill([
                ...array_intersect_key($data, array_flip(['title', 'body'])),
                'revision' => (int) $record->revision + 1,
            ])->save();

            return $record;
        });

        return $this->privateResponse(['draft' => $this->payload($record)]);
    }

    public function submit(Request $request, int $draft): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'status' => ['missing'],
            'owner_staff_id' => ['missing'],
            'submitted_at' => ['missing'],
            'id' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->token($request);

        $record = DB::transaction(function () use ($staff, $token, $draft, $data): EditorialArticle {
            $record = $this->scopedRecord($staff, $draft, true);
            $this->authorizeDraft($staff, $record, 'editorial.draft.submit', $token);

            if ((int) $record->revision !== (int) $data['revision']) {
                abort(409, 'Draft revision conflict.');
            }

            $record->forceFill([
                'status' => 'in_review',
                'submitted_at' => now(),
                'revision' => (int) $record->revision + 1,
            ])->save();

            return $record;
        });

        // This is a review request only: publication, ingestion and indexing
        // are neither invoked nor authorized by this endpoint.
        return $this->privateResponse(['draft' => $this->payload($record)]);
    }

    private function scopedRecord(StaffMember $staff, int $id, bool $lock = false): EditorialArticle
    {
        $query = EditorialArticle::query()
            ->whereKey($id)
            ->where('owner_staff_id', $staff->getKey());

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function authorizeDraft(
        StaffMember $staff,
        EditorialArticle $record,
        string $action,
        ?PersonalAccessToken $token,
    ): void {
        Gate::forUser($staff)->authorize('staff-resource', [
            $action,
            new StaffResourceContext(
                id: (int) $record->getKey(),
                kind: 'editorial_article',
                state: $record->status,
                ownerStaffId: (int) $record->owner_staff_id,
            ),
            $token,
        ]);
    }

    private function token(Request $request): ?PersonalAccessToken
    {
        $presented = $request->bearerToken();

        return $presented ? PersonalAccessToken::findToken($presented) : null;
    }

    private function payload(EditorialArticle $draft, bool $withBody = true): array
    {
        $data = [
            'id' => $draft->getKey(),
            'title' => $draft->title,
            'status' => $draft->status,
            'revision' => $draft->revision,
            'submitted_at' => $draft->submitted_at?->toIso8601String(),
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];

        if ($withBody) {
            $data['body'] = $draft->body;
        }

        return $data;
    }

    private function privateResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
