<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sports\LiveIngestionScheduler;
use App\Domain\Sports\Providers\LicensedSportsProviderAdapter;
use App\Models\Fixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class LiveIngestionSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private LiveIngestionScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->scheduler = new LiveIngestionScheduler();
    }

    public function test_determines_dynamic_polling_intervals_by_status(): void
    {
        $this->assertEquals(15, $this->scheduler->getRecommendedPollIntervalSeconds('live'));
        $this->assertEquals(60, $this->scheduler->getRecommendedPollIntervalSeconds('halftime'));
        $this->assertEquals(300, $this->scheduler->getRecommendedPollIntervalSeconds('scheduled', now()->addMinutes(45)));
        $this->assertEquals(1800, $this->scheduler->getRecommendedPollIntervalSeconds('scheduled', now()->addDays(2)));
        $this->assertEquals(86400, $this->scheduler->getRecommendedPollIntervalSeconds('finished'));
    }

    public function test_detects_fresh_and_stale_live_feeds(): void
    {
        $freshFixture = new Fixture(['status' => 'live']);
        $freshFixture->updated_at = now()->subSeconds(30);

        $freshRes = $this->scheduler->checkFeedFreshness($freshFixture);
        $this->assertFalse($freshRes['is_stale']);
        $this->assertLessThanOrEqual(35, $freshRes['seconds_since_tick']);

        // Stale fixture (>120s)
        Log::shouldReceive('channel')
            ->once()
            ->with('single')
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '[STALE_FEED_ALERT]');
            });

        $staleFixture = new Fixture(['status' => 'live']);
        $staleFixture->id = 999;
        $staleFixture->updated_at = now()->subSeconds(145);

        $staleRes = $this->scheduler->checkFeedFreshness($staleFixture);
        $this->assertTrue($staleRes['is_stale']);
        $this->assertGreaterThanOrEqual(140, $staleRes['seconds_since_tick']);
    }

    public function test_circuit_breaker_trips_after_three_consecutive_failures(): void
    {
        $providerName = 'provider_outage_sandbox';
        $this->assertEquals('closed', $this->scheduler->getCircuitState($providerName));

        // Failure 1
        $res1 = $this->scheduler->recordFailure($providerName, 'Timeout 504');
        $this->assertEquals(1, $res1['failures']);
        $this->assertFalse($res1['circuit_open']);
        $this->assertEquals('closed', $this->scheduler->getCircuitState($providerName));

        // Failure 2
        $res2 = $this->scheduler->recordFailure($providerName, 'Timeout 504');
        $this->assertEquals(2, $res2['failures']);
        $this->assertFalse($res2['circuit_open']);

        // Failure 3: Trips circuit breaker open
        $res3 = $this->scheduler->recordFailure($providerName, 'Timeout 504');
        $this->assertEquals(3, $res3['failures']);
        $this->assertTrue($res3['circuit_open']);
        $this->assertEquals('open', $this->scheduler->getCircuitState($providerName));

        // Executing poll throws exception immediately without executing action
        $executed = false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Circuit breaker is OPEN for provider 'provider_outage_sandbox'");

        $this->scheduler->executeSafePoll($providerName, function () use (&$executed) {
            $executed = true;
        });

        $this->assertFalse($executed);
    }

    public function test_circuit_breaker_resets_on_success(): void
    {
        $providerName = 'flaky_provider';
        $this->scheduler->recordFailure($providerName, 'Temporary 502');
        $this->assertEquals('closed', $this->scheduler->getCircuitState($providerName));

        $result = $this->scheduler->executeSafePoll($providerName, function () {
            return 'ok_payload';
        });

        $this->assertEquals('ok_payload', $result);
        $this->assertEquals('closed', $this->scheduler->getCircuitState($providerName));
    }

    public function test_enforces_quota_limits_during_polling(): void
    {
        // Provider with zero quota remaining
        $adapter = new LicensedSportsProviderAdapter('key', 0);
        $schedulerWithQuota = new LiveIngestionScheduler($adapter);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Daily quota exhausted for provider 'licensed_sports_feed'");

        $schedulerWithQuota->executeSafePoll('licensed_sports_feed', function () {
            return 'should_not_run';
        });
    }
}

