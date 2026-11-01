<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final class ReaderPrivacyController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $user = $this->authorizedReader($request, 'reader:write');
        $this->verifyPassword($request, $user);

        $export = DB::transaction(static function () use ($user): array {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $preference = DB::table('reader_preferences')->where('user_id', $locked->getKey())->first();
            $consent = DB::table('reader_consent_events')
                ->where('user_id', $locked->getKey())->orderBy('id')
                ->get(['id', 'consent_key', 'granted', 'created_at']);
            $deletion = DB::table('reader_deletion_requests')->where('user_id', $locked->getKey())->first();

            return [
                'account' => [
                    'id' => $locked->getKey(),
                    'name' => $locked->name,
                    'email' => $locked->email,
                    'email_verified_at' => $locked->email_verified_at?->toIso8601String(),
                    'created_at' => $locked->created_at?->toIso8601String(),
                ],
                'preferences' => [
                    'preferred_locale' => $preference?->preferred_locale,
                    'analytics_consent' => (bool) ($preference?->analytics_consent ?? false),
                    'personalized_news_enabled' => (bool) ($preference?->personalized_news_enabled ?? false),
                ],
                'favorites' => [
                    'sport_ids' => DB::table('reader_favorite_sports')
                        ->where('user_id', $locked->getKey())->orderBy('sport_id')->pluck('sport_id')->all(),
                    'team_ids' => DB::table('reader_favorite_teams')
                        ->where('user_id', $locked->getKey())->orderBy('team_id')->pluck('team_id')->all(),
                ],
                'consent_history' => $consent->map(static fn (object $event): array => [
                    'id' => (int) $event->id,
                    'consent_key' => $event->consent_key,
                    'granted' => (bool) $event->granted,
                    'recorded_at' => Carbon::parse($event->created_at)->toIso8601String(),
                ])->all(),
                'deletion_request' => $deletion ? [
                    'id' => (int) $deletion->id,
                    'status' => $deletion->status,
                    'requested_at' => Carbon::parse($deletion->requested_at)->toIso8601String(),
                ] : null,
            ];
        });

        return $this->privateResponse(['export' => $export]);
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $user = $this->authorizedReader($request, 'reader:write');
        $this->verifyPassword($request, $user);

        $record = DB::transaction(static function () use ($user): object {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return DB::table('reader_deletion_requests')->where('user_id', $locked->getKey())->first()
                ?? (object) [
                    'id' => DB::table('reader_deletion_requests')->insertGetId([
                        'user_id' => $locked->getKey(),
                        'status' => 'pending_legal_review',
                        'requested_at' => now(),
                    ]),
                ];
        });

        // Do not pretend that a deletion occurred. The record is a request
        // for reviewed handling under an as-yet-unapproved retention policy.
        return $this->privateResponse([
            'request' => [
                'id' => (int) $record->id,
                'status' => 'pending_legal_review',
            ],
            'message' => 'Deletion request recorded; account data has not been deleted.',
        ], 202);
    }

    public function deletionStatus(Request $request): JsonResponse
    {
        $user = $this->authorizedReader($request, 'reader:read');
        $record = DB::table('reader_deletion_requests')
            ->where('user_id', $user->getKey())->first();

        return $this->privateResponse(['request' => $record ? [
            'id' => (int) $record->id,
            'status' => $record->status,
        ] : null]);
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

        $fresh = User::query()->find($reader->getKey());
        if (! $fresh || ! $fresh->hasVerifiedEmail()) {
            abort(403);
        }

        return $fresh;
    }

    private function verifyPassword(Request $request, User $reader): void
    {
        $request->validate([
            'password' => ['required', 'string'],
            'user_id' => ['missing'],
            'email' => ['missing'],
            'status' => ['missing'],
            'retention_override' => ['missing'],
        ]);

        if (! Hash::check($request->input('password'), $reader->password)) {
            abort(403, 'Invalid credentials.');
        }
    }

    private function privateResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
