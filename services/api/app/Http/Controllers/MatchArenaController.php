<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SocialContentSafetyFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

final class MatchArenaController extends Controller
{
    private const OPEN_STATES = ['pre_match_open', 'live_open', 'post_match_open'];

    public function __construct(
        private readonly SocialContentSafetyFilter $safetyFilter,
    ) {
    }

    public function show(Request $request, int $fixtureId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:read');

        $validated = $request->validate([
            'section' => ['sometimes', 'string', Rule::in(['home', 'away', 'neutral'])],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $arena = DB::table('match_arenas as a')
            ->join('fixtures as f', 'f.id', '=', 'a.fixture_id')
            ->join('teams as ht', 'ht.id', '=', 'f.home_team_id')
            ->join('teams as at', 'at.id', '=', 'f.away_team_id')
            ->where('a.fixture_id', $fixtureId)
            ->select([
                'a.id',
                'a.fixture_id',
                'a.state',
                'a.posting_enabled',
                'a.slow_mode_seconds',
                'a.emergency_lock_reason',
                'f.status as fixture_status',
                'f.starts_at',
                'f.home_team_id',
                'ht.name as home_team_name',
                'f.away_team_id',
                'at.name as away_team_name',
                'f.home_score',
                'f.away_score',
            ])
            ->first();

        if (! $arena) {
            return response()->json(['message' => 'Match arena not found.'], 404);
        }

        // Exclude comments from users blocked by the reader or who have blocked the reader.
        $blockedByMe = DB::table('user_blocks')
            ->where('blocker_user_id', $reader->getKey())
            ->pluck('blocked_user_id')
            ->all();
        $blockedMe = DB::table('user_blocks')
            ->where('blocked_user_id', $reader->getKey())
            ->pluck('blocker_user_id')
            ->all();
        $excludedUserIds = array_values(array_unique(array_merge($blockedByMe, $blockedMe)));

        $commentsQuery = DB::table('arena_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.match_arena_id', $arena->id)
            ->where('c.moderation_state', 'visible');

        if (isset($validated['section'])) {
            $commentsQuery->where('c.section', $validated['section']);
        }
        if ($excludedUserIds !== []) {
            $commentsQuery->whereNotIn('c.user_id', $excludedUserIds);
        }

        $limit = (int) ($validated['limit'] ?? 30);
        $comments = $commentsQuery
            ->orderByDesc('c.id')
            ->limit($limit)
            ->get([
                'c.id',
                'c.user_id',
                'u.name as author_name',
                'c.parent_comment_id',
                'c.section',
                'c.body',
                'c.created_at',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'author_name' => $row->author_name,
                'parent_comment_id' => $row->parent_comment_id !== null ? (int) $row->parent_comment_id : null,
                'section' => $row->section,
                'body' => $row->body,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])
            ->all();

        $reactionTotals = DB::table('arena_reactions')
            ->where('match_arena_id', $arena->id)
            ->selectRaw('reaction_type, COUNT(*) as total')
            ->groupBy('reaction_type')
            ->pluck('total', 'reaction_type')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();

        $myReaction = DB::table('arena_reactions')
            ->where('match_arena_id', $arena->id)
            ->where('user_id', $reader->getKey())
            ->value('reaction_type');

        $polls = DB::table('arena_polls')
            ->where('match_arena_id', $arena->id)
            ->orderBy('id')
            ->get()
            ->map(function (object $poll) use ($reader): array {
                $options = DB::table('arena_poll_options as o')
                    ->leftJoin('arena_poll_votes as v', 'v.arena_poll_option_id', '=', 'o.id')
                    ->where('o.arena_poll_id', $poll->id)
                    ->groupBy('o.id', 'o.option_key', 'o.label', 'o.sort_order')
                    ->orderBy('o.sort_order')
                    ->orderBy('o.id')
                    ->selectRaw('o.id, o.option_key, o.label, COUNT(v.id) as votes_count')
                    ->get()
                    ->map(static fn (object $opt): array => [
                        'id' => (int) $opt->id,
                        'option_key' => $opt->option_key,
                        'label' => $opt->label,
                        'votes_count' => (int) $opt->votes_count,
                    ])
                    ->all();

                $myVoteOptionId = DB::table('arena_poll_votes')
                    ->where('arena_poll_id', $poll->id)
                    ->where('user_id', $reader->getKey())
                    ->value('arena_poll_option_id');

                return [
                    'id' => (int) $poll->id,
                    'question_key' => $poll->question_key,
                    'question_text' => $poll->question_text,
                    'is_closed' => (bool) $poll->is_closed,
                    'my_vote_option_id' => $myVoteOptionId !== null ? (int) $myVoteOptionId : null,
                    'options' => $options,
                ];
            })
            ->all();

        return response()->json([
            'arena' => [
                'id' => (int) $arena->id,
                'fixture_id' => (int) $arena->fixture_id,
                'state' => $arena->state,
                'posting_enabled' => (bool) $arena->posting_enabled,
                'slow_mode_seconds' => (int) $arena->slow_mode_seconds,
                'emergency_lock_reason' => $arena->emergency_lock_reason,
                'fixture' => [
                    'status' => $arena->fixture_status,
                    'starts_at' => Carbon::parse($arena->starts_at)->toIso8601String(),
                    'home_team_id' => (int) $arena->home_team_id,
                    'home_team_name' => $arena->home_team_name,
                    'away_team_id' => (int) $arena->away_team_id,
                    'away_team_name' => $arena->away_team_name,
                    'home_score' => $arena->home_score !== null ? (int) $arena->home_score : null,
                    'away_score' => $arena->away_score !== null ? (int) $arena->away_score : null,
                ],
                'reactions' => [
                    'totals' => $reactionTotals,
                    'my_reaction' => $myReaction,
                ],
                'polls' => $polls,
                'comments' => $comments,
            ],
        ]);
    }

    public function postComment(Request $request, int $fixtureId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:write');

        $validated = $request->validate([
            'section' => ['required', 'string', Rule::in(['home', 'away', 'neutral'])],
            'body' => ['required', 'string', 'min:1', 'max:1000'],
            'parent_comment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'user_id' => ['missing'],
            'moderation_state' => ['missing'],
            'moderation_reason' => ['missing'],
        ]);

        return DB::transaction(function () use ($reader, $fixtureId, $validated): JsonResponse {
            $lockedReader = User::query()->whereKey($reader->getKey())->lockForUpdate()->firstOrFail();

            $arena = DB::table('match_arenas as a')
                ->join('fixtures as f', 'f.id', '=', 'a.fixture_id')
                ->where('a.fixture_id', $fixtureId)
                ->select([
                    'a.id',
                    'a.state',
                    'a.posting_enabled',
                    'a.slow_mode_seconds',
                    'f.home_team_id',
                    'f.away_team_id',
                ])
                ->lockForUpdate()
                ->first();

            if (! $arena) {
                return response()->json(['message' => 'Match arena not found.'], 404);
            }

            if (! $arena->posting_enabled || ! in_array($arena->state, self::OPEN_STATES, true)) {
                return response()->json([
                    'message' => 'Match arena discussion is currently locked.',
                ], 409);
            }

            $section = (string) $validated['section'];
            if (in_array($section, ['home', 'away'], true)) {
                $requiredTeamId = $section === 'home'
                    ? (int) $arena->home_team_id
                    : (int) $arena->away_team_id;

                $followsClub = DB::table('reader_favorite_teams')
                    ->where('user_id', $lockedReader->getKey())
                    ->where('team_id', $requiredTeamId)
                    ->exists();

                if (! $followsClub) {
                    return response()->json([
                        'message' => 'Supporter section posting requires following the corresponding club. Use the neutral section instead.',
                    ], 403);
                }
            }

            $parentId = isset($validated['parent_comment_id']) ? (int) $validated['parent_comment_id'] : null;
            if ($parentId !== null) {
                $parent = DB::table('arena_comments')
                    ->where('id', $parentId)
                    ->where('match_arena_id', $arena->id)
                    ->first();

                if (! $parent || $parent->moderation_state !== 'visible' || $parent->section !== $section) {
                    return response()->json([
                        'message' => 'Parent comment is unavailable in this section.',
                    ], 422);
                }

                $blockExists = DB::table('user_blocks')
                    ->where(function ($q) use ($lockedReader, $parent): void {
                        $q->where('blocker_user_id', $lockedReader->getKey())
                            ->where('blocked_user_id', $parent->user_id);
                    })
                    ->orWhere(function ($q) use ($lockedReader, $parent): void {
                        $q->where('blocker_user_id', $parent->user_id)
                            ->where('blocked_user_id', $lockedReader->getKey());
                    })
                    ->exists();

                if ($blockExists) {
                    return response()->json([
                        'message' => 'Cannot reply due to an active user block.',
                    ], 403);
                }
            }

            $slowMode = (int) $arena->slow_mode_seconds;
            if ($slowMode > 0) {
                $latestCommentAt = DB::table('arena_comments')
                    ->where('match_arena_id', $arena->id)
                    ->where('user_id', $lockedReader->getKey())
                    ->orderByDesc('created_at')
                    ->value('created_at');

                if ($latestCommentAt !== null) {
                    $nextAllowedAt = Carbon::parse($latestCommentAt)->addSeconds($slowMode);
                    if ($nextAllowedAt->isFuture()) {
                        return response()->json([
                            'message' => 'Slow mode is active in this match arena.',
                            'retry_after_seconds' => max(1, (int) now()->diffInSeconds($nextAllowedAt)),
                        ], 429);
                    }
                }
            }

            $evaluated = $this->safetyFilter->evaluate((string) $validated['body']);
            if ($evaluated['body'] === '') {
                return response()->json([
                    'message' => 'Comment body cannot be empty.',
                    'errors' => ['body' => ['Comment body cannot be empty.']],
                ], 422);
            }

            $now = now();
            $commentId = DB::table('arena_comments')->insertGetId([
                'match_arena_id' => $arena->id,
                'user_id' => $lockedReader->getKey(),
                'parent_comment_id' => $parentId,
                'section' => $section,
                'body' => $evaluated['body'],
                'moderation_state' => $evaluated['moderation_state'],
                'moderation_reason' => $evaluated['moderation_reason'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $status = $evaluated['moderation_state'] === 'visible' ? 201 : 202;

            return response()->json([
                'comment' => [
                    'id' => (int) $commentId,
                    'match_arena_id' => (int) $arena->id,
                    'section' => $section,
                    'parent_comment_id' => $parentId,
                    'body' => $evaluated['body'],
                    'moderation_state' => $evaluated['moderation_state'],
                    'moderation_reason' => $evaluated['moderation_reason'],
                    'created_at' => $now->toIso8601String(),
                ],
            ], $status);
        });
    }

    public function react(Request $request, int $fixtureId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:write');

        $validated = $request->validate([
            'reaction_type' => [
                'required',
                'string',
                Rule::in(['fire', 'goal_hype', 'tactical_applause', 'shock', 'heartbreak', 'whistle_protest']),
            ],
            'user_id' => ['missing'],
        ]);

        return DB::transaction(function () use ($reader, $fixtureId, $validated): JsonResponse {
            $arena = DB::table('match_arenas')
                ->where('fixture_id', $fixtureId)
                ->lockForUpdate()
                ->first();

            if (! $arena) {
                return response()->json(['message' => 'Match arena not found.'], 404);
            }

            if (! $arena->posting_enabled || ! in_array($arena->state, self::OPEN_STATES, true)) {
                return response()->json(['message' => 'Match arena reactions are locked.'], 409);
            }

            $now = now();
            DB::table('arena_reactions')->updateOrInsert(
                [
                    'match_arena_id' => $arena->id,
                    'user_id' => $reader->getKey(),
                ],
                [
                    'reaction_type' => $validated['reaction_type'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            return response()->json([
                'reaction' => [
                    'match_arena_id' => (int) $arena->id,
                    'reaction_type' => $validated['reaction_type'],
                ],
            ]);
        });
    }

    public function votePoll(Request $request, int $fixtureId, int $pollId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:write');

        $validated = $request->validate([
            'option_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['missing'],
        ]);

        return DB::transaction(function () use ($reader, $fixtureId, $pollId, $validated): JsonResponse {
            $lockedReader = User::query()->whereKey($reader->getKey())->lockForUpdate()->firstOrFail();

            $arena = DB::table('match_arenas')->where('fixture_id', $fixtureId)->first();
            if (! $arena) {
                return response()->json(['message' => 'Match arena not found.'], 404);
            }

            $poll = DB::table('arena_polls')
                ->where('id', $pollId)
                ->where('match_arena_id', $arena->id)
                ->lockForUpdate()
                ->first();

            if (! $poll) {
                return response()->json(['message' => 'Poll not found in this match arena.'], 404);
            }

            if ($poll->is_closed || ($poll->closes_at !== null && Carbon::parse($poll->closes_at)->isPast())) {
                return response()->json(['message' => 'Poll is closed.'], 409);
            }

            $option = DB::table('arena_poll_options')
                ->where('id', (int) $validated['option_id'])
                ->where('arena_poll_id', $poll->id)
                ->first();

            if (! $option) {
                return response()->json([
                    'message' => 'Invalid poll option.',
                    'errors' => ['option_id' => ['Option does not belong to this poll.']],
                ], 422);
            }

            $alreadyVoted = DB::table('arena_poll_votes')
                ->where('arena_poll_id', $poll->id)
                ->where('user_id', $lockedReader->getKey())
                ->exists();

            if ($alreadyVoted) {
                return response()->json(['message' => 'You have already voted in this poll.'], 409);
            }

            $now = now();
            DB::table('arena_poll_votes')->insert([
                'arena_poll_id' => $poll->id,
                'arena_poll_option_id' => $option->id,
                'user_id' => $lockedReader->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json([
                'vote' => [
                    'poll_id' => (int) $poll->id,
                    'option_id' => (int) $option->id,
                    'option_key' => $option->option_key,
                ],
            ], 201);
        });
    }

    public function reportComment(Request $request, int $commentId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:write');

        $validated = $request->validate([
            'reason_category' => [
                'required',
                'string',
                Rule::in([
                    'abuse',
                    'hate_speech',
                    'harassment',
                    'spam',
                    'misinformation',
                    'impersonation',
                    'illegal_content',
                ]),
            ],
            'details' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reporter_user_id' => ['missing'],
            'status' => ['missing'],
        ]);

        return DB::transaction(function () use ($reader, $commentId, $validated): JsonResponse {
            $lockedReader = User::query()->whereKey($reader->getKey())->lockForUpdate()->firstOrFail();

            $comment = DB::table('arena_comments')->where('id', $commentId)->lockForUpdate()->first();
            if (! $comment) {
                return response()->json(['message' => 'Comment not found.'], 404);
            }

            if ((int) $comment->user_id === (int) $lockedReader->getKey()) {
                return response()->json([
                    'message' => 'You cannot report your own comment.',
                ], 422);
            }

            $exists = DB::table('moderation_reports')
                ->where('reporter_user_id', $lockedReader->getKey())
                ->where('arena_comment_id', $comment->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'You have already reported this comment.',
                ], 409);
            }

            $now = now();
            $reportId = DB::table('moderation_reports')->insertGetId([
                'reporter_user_id' => $lockedReader->getKey(),
                'arena_comment_id' => $comment->id,
                'reason_category' => $validated['reason_category'],
                'details' => $validated['details'] ?? null,
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json([
                'report' => [
                    'id' => (int) $reportId,
                    'arena_comment_id' => (int) $comment->id,
                    'reason_category' => $validated['reason_category'],
                    'status' => 'open',
                ],
            ], 201);
        });
    }

    public function blockUser(Request $request, int $targetUserId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:write');

        if ($targetUserId === (int) $reader->getKey()) {
            return response()->json(['message' => 'You cannot block your own account.'], 422);
        }

        return DB::transaction(function () use ($reader, $targetUserId): JsonResponse {
            $lockedReader = User::query()->whereKey($reader->getKey())->lockForUpdate()->firstOrFail();
            $targetExists = User::query()->whereKey($targetUserId)->exists();
            if (! $targetExists) {
                return response()->json(['message' => 'Target user not found.'], 404);
            }

            DB::table('user_blocks')->updateOrInsert(
                [
                    'blocker_user_id' => $lockedReader->getKey(),
                    'blocked_user_id' => $targetUserId,
                ],
                [
                    'created_at' => now(),
                ],
            );

            return response()->json([
                'block' => [
                    'blocked_user_id' => $targetUserId,
                    'active' => true,
                ],
            ]);
        });
    }

    public function unblockUser(Request $request, int $targetUserId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:write');

        DB::table('user_blocks')
            ->where('blocker_user_id', $reader->getKey())
            ->where('blocked_user_id', $targetUserId)
            ->delete();

        return response()->json([
            'block' => [
                'blocked_user_id' => $targetUserId,
                'active' => false,
            ],
        ]);
    }

    private function authorizedReader(Request $request, string $ability): User
    {
        $reader = $request->user();
        $raw = $request->bearerToken();
        $token = $raw ? PersonalAccessToken::findToken($raw) : null;
        if (! $reader instanceof User || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || $token->tokenable_type !== $reader->getMorphClass()
            || (string) $token->tokenable_id !== (string) $reader->getKey()
            || ! $token->can($ability)) {
            abort(403);
        }

        return $reader;
    }
}
