<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Competition;
use App\Models\Team;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserFollowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $follows = UserFollow::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $teamIds = $follows->where('followable_type', 'team')->pluck('followable_id')->all();
        $competitionIds = $follows->where('followable_type', 'competition')->pluck('followable_id')->all();
        $athleteIds = $follows->where('followable_type', 'athlete')->pluck('followable_id')->all();

        $teams = Team::whereIn('id', $teamIds)->get()->keyBy('id');
        $competitions = Competition::whereIn('id', $competitionIds)->get()->keyBy('id');
        $athletes = Athlete::with('currentTeam')->whereIn('id', $athleteIds)->get()->keyBy('id');

        $items = $follows->map(function (UserFollow $follow) use ($teams, $competitions, $athletes) {
            $entity = null;
            if ($follow->followable_type === 'team') {
                $t = $teams->get($follow->followable_id);
                $entity = $t ? ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'logo_url' => $t->logo_url] : null;
            } elseif ($follow->followable_type === 'competition') {
                $c = $competitions->get($follow->followable_id);
                $entity = $c ? ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'country_code' => $c->country_code] : null;
            } elseif ($follow->followable_type === 'athlete') {
                $a = $athletes->get($follow->followable_id);
                $entity = $a ? ['id' => $a->id, 'name' => $a->name, 'slug' => $a->slug, 'position' => $a->position, 'team' => $a->currentTeam?->name] : null;
            }

            return [
                'id' => $follow->id,
                'type' => $follow->followable_type,
                'target_id' => $follow->followable_id,
                'entity' => $entity,
                'notify_match_start' => $follow->notify_match_start,
                'notify_goals' => $follow->notify_goals,
                'created_at' => $follow->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'follows' => $items,
        ], 200, [
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'followable_type' => ['required', 'string', 'in:team,competition,athlete'],
            'followable_id' => ['required', 'integer'],
            'notify_match_start' => ['sometimes', 'boolean'],
            'notify_goals' => ['sometimes', 'boolean'],
        ]);

        $type = $validated['followable_type'];
        $targetId = (int) $validated['followable_id'];

        $exists = match ($type) {
            'team' => Team::whereKey($targetId)->exists(),
            'competition' => Competition::whereKey($targetId)->exists(),
            'athlete' => Athlete::whereKey($targetId)->exists(),
        };

        if (! $exists) {
            return response()->json([
                'message' => "Target {$type} not found",
            ], 404);
        }

        $follow = UserFollow::updateOrCreate(
            [
                'user_id' => $user->id,
                'followable_type' => $type,
                'followable_id' => $targetId,
            ],
            [
                'notify_match_start' => $validated['notify_match_start'] ?? true,
                'notify_goals' => $validated['notify_goals'] ?? true,
            ]
        );

        return response()->json([
            'id' => $follow->id,
            'type' => $follow->followable_type,
            'target_id' => $follow->followable_id,
            'notify_match_start' => $follow->notify_match_start,
            'notify_goals' => $follow->notify_goals,
            'created_at' => $follow->created_at?->toIso8601String(),
        ], 201, [
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function destroy(Request $request, string $type, int|string $targetId): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($type, ['team', 'competition', 'athlete'], true)) {
            return response()->json(['message' => 'Invalid followable type'], 422);
        }

        $deleted = UserFollow::query()
            ->where('user_id', $user->id)
            ->where('followable_type', $type)
            ->where('followable_id', (int) $targetId)
            ->delete();

        return response()->json([
            'deleted' => $deleted > 0,
        ], 200, [
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}

