<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class VoiceTribuneController extends Controller
{
    private const ALLOWED_PHASE_TAGS = [
        'MAÇ ÖNÜ ISINMA',
        'MAÇ ANINDA CANLI',
        'MAÇ SONU 3. DEVRE',
    ];

    public function index(int $fixtureId): JsonResponse
    {
        $fixture = DB::table('fixtures')->where('id', $fixtureId)->first();
        if (! $fixture) {
            return response()->json(['message' => 'Fixture not found.'], 404);
        }

        $rooms = DB::table('voice_tribune_rooms as vr')
            ->leftJoin('teams as t', 't.id', '=', 'vr.club_id')
            ->join('users as u', 'u.id', '=', 'vr.created_by_user_id')
            ->where('vr.fixture_id', $fixtureId)
            ->where('vr.is_active', true)
            ->select([
                'vr.id',
                'vr.fixture_id',
                'vr.title',
                'vr.phase_tag',
                'vr.listeners_count',
                'vr.club_id',
                't.name as club_name',
                't.slug as club_slug',
                'vr.created_by_user_id',
                'u.name as host_name',
                'vr.created_at',
            ])
            ->orderBy('vr.listeners_count', 'desc')
            ->orderBy('vr.id', 'desc')
            ->get();

        return response()->json([
            'fixture_id' => $fixtureId,
            'rooms' => $rooms,
        ], 200, [
            'Cache-Control' => 'public, max-age=10, stale-while-revalidate=30',
        ]);
    }

    public function store(Request $request, int $fixtureId): JsonResponse
    {
        $fixture = DB::table('fixtures')->where('id', $fixtureId)->first();
        if (! $fixture) {
            return response()->json(['message' => 'Fixture not found.'], 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'phase_tag' => ['required', 'string', Rule::in(self::ALLOWED_PHASE_TAGS)],
            'club_id' => ['nullable', 'integer', 'exists:teams,id'],
        ]);

        $userId = $request->user()->id;
        $now = now();

        $roomId = DB::table('voice_tribune_rooms')->insertGetId([
            'fixture_id' => $fixtureId,
            'club_id' => $validated['club_id'] ?? null,
            'created_by_user_id' => $userId,
            'title' => $validated['title'],
            'phase_tag' => $validated['phase_tag'],
            'listeners_count' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $room = DB::table('voice_tribune_rooms as vr')
            ->leftJoin('teams as t', 't.id', '=', 'vr.club_id')
            ->join('users as u', 'u.id', '=', 'vr.created_by_user_id')
            ->where('vr.id', $roomId)
            ->select([
                'vr.id',
                'vr.fixture_id',
                'vr.title',
                'vr.phase_tag',
                'vr.listeners_count',
                'vr.club_id',
                't.name as club_name',
                'vr.created_by_user_id',
                'u.name as host_name',
                'vr.created_at',
            ])
            ->first();

        return response()->json([
            'room' => $room,
            'xp_awarded' => 40,
        ], 201);
    }

    public function action(Request $request, int $roomId): JsonResponse
    {
        $room = DB::table('voice_tribune_rooms')->where('id', $roomId)->first();
        if (! $room) {
            return response()->json(['message' => 'Voice room not found.'], 404);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['toggle-mic', 'toggle-hand', 'react', 'toggle-join'])],
            'emoji' => ['nullable', 'string', 'max:16'],
        ]);

        if ($validated['action'] === 'toggle-join') {
            DB::table('voice_tribune_rooms')
                ->where('id', $roomId)
                ->increment('listeners_count');
        }

        return response()->json([
            'success' => true,
            'room_id' => $roomId,
            'action' => $validated['action'],
            'emoji' => $validated['emoji'] ?? null,
        ]);
    }
}
