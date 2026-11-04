<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sports\StatisticsDictionary;
use InvalidArgumentException;
use Tests\TestCase;

final class NormalizedStatisticsDictionaryTest extends TestCase
{
    private StatisticsDictionary $dictionary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dictionary = new StatisticsDictionary();
    }

    public function test_football_metrics_validate_within_bounds(): void
    {
        $this->dictionary->validateMetric('futbol', 'possession_pct', 62);
        $this->dictionary->validateMetric('futbol', 'expected_goals', 2.34);
        $this->dictionary->validateMetric('futbol', 'shots_on_target', 7);

        // Percentage over 100 must fail
        $this->expectException(InvalidArgumentException::class);
        $this->dictionary->validateMetric('futbol', 'possession_pct', 105);
    }

    public function test_cross_sport_metrics_cannot_be_conflated(): void
    {
        // Rebounds cannot be stored on a football match
        $this->expectException(InvalidArgumentException::class);
        $this->dictionary->validateMetric('futbol', 'rebounds_offensive', 12);
    }

    public function test_basketball_metrics_validate_correctly(): void
    {
        $this->dictionary->validateMetric('basketbol', 'points', 98);
        $this->dictionary->validateMetric('basketbol', 'three_pointers_pct', 41.5);
        $this->dictionary->validateMetric('basketbol', 'rebounds_defensive', 28);

        // xG cannot be stored on a basketball match
        $this->expectException(InvalidArgumentException::class);
        $this->dictionary->validateMetric('basketbol', 'expected_goals', 1.8);
    }

    public function test_sanitize_metrics_filters_disallowed_keys(): void
    {
        $input = [
            'possession_pct' => 58,
            'shots_total' => 14,
            'rebounds_total' => 30, // invalid for football
            'unknown_junk' => 'bad',
        ];

        $clean = $this->dictionary->sanitizeMetrics('futbol', $input);

        $this->assertArrayHasKey('possession_pct', $clean);
        $this->assertArrayHasKey('shots_total', $clean);
        $this->assertArrayNotHasKey('rebounds_total', $clean);
        $this->assertArrayNotHasKey('unknown_junk', $clean);
    }
}

