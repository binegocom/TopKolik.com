<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ArenaComment;
use App\Models\EditorialArticle;
use App\Models\Fixture;
use App\Models\UserSavedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserSavedItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $items = UserSavedItem::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $fixtureIds = $items->where('item_type', 'fixture')->pluck('item_id')->all();
        $articleIds = $items->where('item_type', 'article')->pluck('item_id')->all();
        $commentIds = $items->where('item_type', 'arena_comment')->pluck('item_id')->all();

        $fixtures = Fixture::with(['competition', 'homeTeam', 'awayTeam'])->whereIn('id', $fixtureIds)->get()->keyBy('id');
        $articles = EditorialArticle::whereIn('id', $articleIds)->get()->keyBy('id');
        $comments = ArenaComment::with('user')->whereIn('id', $commentIds)->get()->keyBy('id');

        $result = $items->map(function (UserSavedItem $saved) use ($fixtures, $articles, $comments) {
            $entity = null;
            if ($saved->item_type === 'fixture') {
                $f = $fixtures->get($saved->item_id);
                $entity = $f ? [
                    'id' => $f->id,
                    'competition' => $f->competition?->name,
                    'home_team' => $f->homeTeam?->name,
                    'away_team' => $f->awayTeam?->name,
                    'status' => $f->status,
                    'starts_at' => $f->starts_at?->toIso8601String(),
                ] : null;
            } elseif ($saved->item_type === 'article') {
                $a = $articles->get($saved->item_id);
                $entity = $a ? [
                    'id' => $a->id,
                    'headline' => $a->headline,
                    'slug' => $a->slug,
                    'published_at' => $a->published_at?->toIso8601String(),
                ] : null;
            } elseif ($saved->item_type === 'arena_comment') {
                $c = $comments->get($saved->item_id);
                $entity = $c ? [
                    'id' => $c->id,
                    'author' => $c->user?->name,
                    'section' => $c->section,
                    'body' => $c->body,
                ] : null;
            }

            return [
                'id' => $saved->id,
                'item_type' => $saved->item_type,
                'item_id' => $saved->item_id,
                'entity' => $entity,
                'notes' => $saved->notes,
                'created_at' => $saved->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'saved_items' => $result,
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
            'item_type' => ['required', 'string', 'in:fixture,article,arena_comment'],
            'item_id' => ['required', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $itemType = $validated['item_type'];
        $itemId = (int) $validated['item_id'];

        $exists = match ($itemType) {
            'fixture' => Fixture::whereKey($itemId)->exists(),
            'article' => EditorialArticle::whereKey($itemId)->exists(),
            'arena_comment' => ArenaComment::whereKey($itemId)->exists(),
        };

        if (! $exists) {
            return response()->json([
                'message' => "Target {$itemType} not found",
            ], 404);
        }

        $saved = UserSavedItem::updateOrCreate(
            [
                'user_id' => $user->id,
                'item_type' => $itemType,
                'item_id' => $itemId,
            ],
            [
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'id' => $saved->id,
            'item_type' => $saved->item_type,
            'item_id' => $saved->item_id,
            'notes' => $saved->notes,
            'created_at' => $saved->created_at?->toIso8601String(),
        ], 201, [
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function destroy(Request $request, string $itemType, int|string $itemId): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($itemType, ['fixture', 'article', 'arena_comment'], true)) {
            return response()->json(['message' => 'Invalid item type'], 422);
        }

        $deleted = UserSavedItem::query()
            ->where('user_id', $user->id)
            ->where('item_type', $itemType)
            ->where('item_id', (int) $itemId)
            ->delete();

        return response()->json([
            'deleted' => $deleted > 0,
        ], 200, [
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
