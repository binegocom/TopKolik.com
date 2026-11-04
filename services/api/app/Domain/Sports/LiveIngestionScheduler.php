<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use App\Domain\Sports\Contracts\SportsDataProviderInterface;
use App\Models\Fixture;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LiveIngestionScheduler
{
    private const CIRCUIT_KEY_PREFIX = 'sports_circuit_breaker:';
    private const FAILURE_THRESHOLD = 3;
    private const BASE_COOLDOWN_SECONDS = 30;
    private const MAX_COOLDOWN_SECONDS = 900;
    private const STALE_FEED_THRESHOLD_SECONDS = 120;

    public function __construct(
        private readonly ?SportsDataProviderInterface $provider = null,
    ) {}

    /**
     * Determine optimal poll interval in seconds based on fixture lifecycle state.
     */
    public function getRecommendedPollIntervalSeconds(string $status, ?DateTimeInterface $startsAt = null): int
    {
        return match ($status) {
            'live' => 15,
            'halftime' => 60,
            'scheduled' => $this->getScheduledInterval($startsAt),
            'finished', 'cancelled', 'postponed' => 86400,
            default => 300,
        };
    }

    private function getScheduledInterval(?DateTimeInterface $startsAt): int
    {
        if ($startsAt === null) {
            return 1800;
        }

        $diffMinutes = now()->diffInMinutes($startsAt, false);
        if ($diffMinutes <= 120 && $diffMinutes >= 0) {
            return 300; // 5 minutes when close to kickoff
        }

        return 1800; // 30 minutes for future fixtures
    }

    /**
     * Check freshness of a live fixture feed.
     * Flags and logs alert if live match has received no tick for > 120 seconds.
     */
    public function checkFeedFreshness(Fixture $fixture, ?DateTimeInterface $lastTickAt = null): array
    {
        if ($fixture->status !== 'live') {
            return ['is_stale' => false, 'seconds_since_tick' => 0];
        }

        $tickTime = $lastTickAt ?? $fixture->updated_at;
        $secondsSinceTick = $tickTime !== null ? abs(now()->getTimestamp() - $tickTime->getTimestamp()) : 9999;

        $isStale = $secondsSinceTick > self::STALE_FEED_THRESHOLD_SECONDS;

        if ($isStale) {
            Log::channel('single')->warning("[STALE_FEED_ALERT] Fixture {$fixture->id} live feed stale ({$secondsSinceTick}s since last tick)");
        }

        return [
            'is_stale' => $isStale,
            'seconds_since_tick' => $secondsSinceTick,
            'threshold_seconds' => self::STALE_FEED_THRESHOLD_SECONDS,
        ];
    }

    /**
     * Circuit breaker check: returns 'closed', 'open', or 'half_open'.
     */
    public function getCircuitState(string $providerName): string
    {
        $data = Cache::get(self::CIRCUIT_KEY_PREFIX . $providerName);
        if (! $data) {
            return 'closed';
        }

        $failures = $data['failures'] ?? 0;
        $openedAt = $data['opened_at'] ?? 0;
        $cooldown = $data['cooldown'] ?? self::BASE_COOLDOWN_SECONDS;

        if ($failures >= self::FAILURE_THRESHOLD) {
            $elapsed = now()->getTimestamp() - $openedAt;
            if ($elapsed >= $cooldown) {
                return 'half_open';
            }

            return 'open';
        }

        return 'closed';
    }

    /**
     * Record a successful call to provider, resetting circuit breaker.
     */
    public function recordSuccess(string $providerName): void
    {
        Cache::forget(self::CIRCUIT_KEY_PREFIX . $providerName);
    }

    /**
     * Record a failed call to provider, managing exponential backoff cooldown.
     */
    public function recordFailure(string $providerName, ?string $errorMessage = null): array
    {
        $current = Cache::get(self::CIRCUIT_KEY_PREFIX . $providerName, [
            'failures' => 0,
            'cooldown' => self::BASE_COOLDOWN_SECONDS,
            'opened_at' => null,
        ]);

        $failures = $current['failures'] + 1;
        $cooldown = min(self::MAX_COOLDOWN_SECONDS, self::BASE_COOLDOWN_SECONDS * (2 ** ($failures - 1)));
        $openedAt = $failures >= self::FAILURE_THRESHOLD ? now()->getTimestamp() : null;

        $state = [
            'failures' => $failures,
            'cooldown' => $cooldown,
            'opened_at' => $openedAt,
            'last_error' => $errorMessage,
        ];

        Cache::put(self::CIRCUIT_KEY_PREFIX . $providerName, $state, now()->addHours(6));

        if ($failures >= self::FAILURE_THRESHOLD) {
            Log::channel('single')->emergency("[CIRCUIT_BREAKER_OPENED] Provider '{$providerName}' reached {$failures} failures. Cooldown: {$cooldown}s.");
        }

        return [
            'failures' => $failures,
            'cooldown' => $cooldown,
            'circuit_open' => $failures >= self::FAILURE_THRESHOLD,
        ];
    }

    /**
     * Execute an ingestion poll with quota protection and circuit breaker guards.
     */
    public function executeSafePoll(string $providerName, callable $action): mixed
    {
        $circuitState = $this->getCircuitState($providerName);
        if ($circuitState === 'open') {
            throw new RuntimeException("Circuit breaker is OPEN for provider '{$providerName}'. Ingestion paused.");
        }

        if ($this->provider !== null) {
            if ($this->provider->getRemainingQuota() <= 0) {
                throw new RuntimeException("Daily quota exhausted for provider '{$providerName}'.");
            }
        }

        try {
            $result = $action();
            $this->recordSuccess($providerName);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($providerName, $e->getMessage());
            throw $e;
        }
    }
}
