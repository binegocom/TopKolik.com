<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceReaderFavoritesRequest;
use App\Http\Requests\UpdateReaderPreferencesRequest;
use App\Http\Requests\UpdateReaderProfileRequest;
use App\Models\ReaderPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReaderProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
            'preferences' => $this->preferencesPayload($request, $user),
            'favorites' => $this->favoritesPayload($user),
        ]);
    }

    public function update(UpdateReaderProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['name' => trim($request->validated('name'))]);

        return response()->json(['user' => $this->userPayload($user->refresh())]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => $this->preferencesPayload($request, $request->user()),
        ]);
    }

    public function updatePreferences(UpdateReaderPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $values = $request->validated();

        DB::transaction(static function () use ($user, $values): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($values !== []) {
                $previous = ReaderPreference::query()->where('user_id', $user->getKey())->first();
                ReaderPreference::query()->updateOrCreate(
                    ['user_id' => $user->getKey()],
                    $values,
                );

                // Serialize with the locked user row so simultaneous consent
                // changes cannot reorder or duplicate the recorded decisions.
                foreach (['analytics_consent', 'personalized_news_enabled'] as $consentKey) {
                    if (! array_key_exists($consentKey, $values)) {
                        continue;
                    }

                    $old = (bool) ($previous?->{$consentKey} ?? false);
                    $new = (bool) $values[$consentKey];
                    if ($old !== $new) {
                        DB::table('reader_consent_events')->insert([
                            'user_id' => $user->getKey(),
                            'consent_key' => $consentKey,
                            'granted' => $new,
                            'created_at' => now(),
                        ]);
                    }
                }
            }
        });

        $user->unsetRelation('readerPreference');

        return response()->json([
            'preferences' => $this->preferencesPayload($request, $user),
        ]);
    }

    public function favorites(Request $request): JsonResponse
    {
        return response()->json(['favorites' => $this->favoritesPayload($request->user())]);
    }

    public function replaceFavorites(ReplaceReaderFavoritesRequest $request): JsonResponse
    {
        $user = $request->user();
        $values = $request->validated();

        DB::transaction(static function () use ($user, $values): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // Both collections must be explicitly supplied. A failed validation
            // leaves the existing selection untouched.
            $user->favoriteSports()->sync($values['sport_ids']);
            $user->favoriteTeams()->sync($values['team_ids']);
        });

        return response()->json(['favorites' => $this->favoritesPayload($user)]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ];
    }

    private function preferencesPayload(Request $request, User $user): array
    {
        $pref = $user->readerPreference;
        $preferred = $pref?->preferred_locale;
        $supported = ['de', 'fr', 'it', 'en'];
        $detected = 'en';

        if ($preferred === null) {
            // Symfony orders Accept-Language choices by quality. Region variants
            // resolve to the base language; unsupported device locales fall back to en.
            foreach ($request->getLanguages() as $language) {
                $base = explode('_', strtolower(str_replace('-', '_', $language)))[0];
                if (in_array($base, $supported, true)) {
                    $detected = $base;
                    break;
                }
            }
        }

        return [
            'preferred_locale' => $preferred,
            'effective_locale' => $preferred ?? $detected,
            'personalized_news_enabled' => $pref?->personalized_news_enabled ?? false,
            'analytics_consent' => $pref?->analytics_consent ?? false,
            'reading_history' => ['supported' => false, 'enabled' => false],
        ];
    }

    private function favoritesPayload(User $user): array
    {
        return [
            'sports' => $user->favoriteSports()
                ->orderBy('sports.id')
                ->get(['sports.id', 'sports.slug', 'sports.name'])
                ->map(fn ($sport) => [
                    'id' => $sport->id,
                    'slug' => $sport->slug,
                    'name' => $sport->name,
                ])->all(),
            'teams' => $user->favoriteTeams()
                ->orderBy('teams.id')
                ->get(['teams.id', 'teams.slug', 'teams.name', 'teams.sport_id'])
                ->map(fn ($team) => [
                    'id' => $team->id,
                    'sport_id' => $team->sport_id,
                    'slug' => $team->slug,
                    'name' => $team->name,
                ])->all(),
        ];
    }
}
