<?php

declare(strict_types=1);

namespace App\Domain\Sports\Providers;

use App\Domain\Sports\Contracts\SportsDataProviderInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class LicensedSportsProviderAdapter implements SportsDataProviderInterface
{
    private const PROVIDER_NAME = 'licensed_sports_feed';
    private const DEFAULT_DAILY_QUOTA = 10000;

    public function __construct(
        private readonly string $apiKey = 'topkolik_licensed_key_sandbox',
        private readonly int $dailyQuota = self::DEFAULT_DAILY_QUOTA,
    ) {}

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDailyQuotaLimit(): int
    {
        return $this->dailyQuota;
    }

    public function getRemainingQuota(): int
    {
        $key = 'sports_provider_calls:' . now()->format('Y-m-d');
        $used = (int) Cache::get($key, 0);

        return max(0, $this->dailyQuota - $used);
    }

    public function consumeQuotaCall(): void
    {
        $key = 'sports_provider_calls:' . now()->format('Y-m-d');
        $used = (int) Cache::get($key, 0);

        if ($used >= $this->dailyQuota) {
            throw new RuntimeException("Daily quota exceeded for provider " . self::PROVIDER_NAME);
        }

        Cache::put($key, $used + 1, now()->endOfDay());
    }

    public function fetchFixtures(string $competitionRef, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        $this->consumeQuotaCall();

        // In sandbox/licensed testing environment, returns validated structural payloads
        return [
            [
                'provider_fixture_id' => "ext-fix-{$competitionRef}-01",
                'competition_ref' => $competitionRef,
                'home_team_ref' => 'ext-team-gs',
                'away_team_ref' => 'ext-team-fb',
                'starts_at' => now()->addDays(2)->toIso8601String(),
                'status' => 'scheduled',
            ],
            [
                'provider_fixture_id' => "ext-fix-{$competitionRef}-02",
                'competition_ref' => $competitionRef,
                'home_team_ref' => 'ext-team-bjk',
                'away_team_ref' => 'ext-team-ts',
                'starts_at' => now()->addDays(3)->toIso8601String(),
                'status' => 'scheduled',
            ],
        ];
    }

    public function fetchLiveMatchState(string $fixtureRef): array
    {
        $this->consumeQuotaCall();

        return [
            'provider_fixture_id' => $fixtureRef,
            'status' => 'live',
            'minute' => 67,
            'home_score' => 2,
            'away_score' => 1,
            'events' => [
                ['type' => 'goal', 'minute' => 22, 'player' => 'Mauro Icardi', 'team' => 'home'],
                ['type' => 'goal', 'minute' => 45, 'player' => 'Edin Džeko', 'team' => 'away'],
                ['type' => 'goal', 'minute' => 61, 'player' => 'Victor Osimhen', 'team' => 'home'],
            ],
        ];
    }

    public function fetchTeamRoster(string $teamRef): array
    {
        $this->consumeQuotaCall();

        return [
            'provider_team_id' => $teamRef,
            'athletes' => [
                ['name' => 'Fernando Muslera', 'shirt_number' => 1, 'position' => 'GK', 'nationality' => 'Uruguay'],
                ['name' => 'Lucas Torreira', 'shirt_number' => 34, 'position' => 'MF', 'nationality' => 'Uruguay'],
                ['name' => 'Victor Osimhen', 'shirt_number' => 45, 'position' => 'FW', 'nationality' => 'Nigeria'],
            ],
        ];
    }
}

