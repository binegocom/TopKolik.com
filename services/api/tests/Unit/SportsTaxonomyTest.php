<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sports\SportsTaxonomyService;
use App\Models\Competition;
use App\Models\Sport;
use InvalidArgumentException;
use Tests\TestCase;

final class SportsTaxonomyTest extends TestCase
{
    private SportsTaxonomyService $taxonomy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxonomy = new SportsTaxonomyService();
    }

    public function test_supported_sports_are_present_in_taxonomy(): void
    {
        $sports = $this->taxonomy->getSupportedSports();

        $this->assertContains('futbol', $sports);
        $this->assertContains('basketbol', $sports);
        $this->assertContains('voleybol', $sports);
    }

    public function test_football_metadata_contains_standard_scoring_and_periods(): void
    {
        $meta = $this->taxonomy->getSportMetadata('futbol');

        $this->assertSame('Futbol', $meta['name']);
        $this->assertSame('SOC', $meta['code']);
        $this->assertSame('goals', $meta['scoring_unit']);
        $this->assertContains('1H', $meta['periods']);
        $this->assertContains('2H', $meta['periods']);
        $this->assertContains('xG', $meta['capabilities']);
    }

    public function test_basketball_and_volleyball_have_distinct_period_structures(): void
    {
        $basket = $this->taxonomy->getSportMetadata('basketbol');
        $this->assertSame('points', $basket['scoring_unit']);
        $this->assertContains('Q1', $basket['periods']);
        $this->assertContains('Q4', $basket['periods']);

        $volley = $this->taxonomy->getSportMetadata('voleybol');
        $this->assertSame('sets_and_points', $volley['scoring_unit']);
        $this->assertContains('S1', $volley['periods']);
        $this->assertContains('S5', $volley['periods']);
    }

    public function test_unsupported_sport_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->taxonomy->getSportMetadata('cricket');
    }

    public function test_validate_period_for_sport(): void
    {
        $this->assertTrue($this->taxonomy->validatePeriodForSport('futbol', '1H'));
        $this->assertFalse($this->taxonomy->validatePeriodForSport('futbol', 'Q1'));
        $this->assertTrue($this->taxonomy->validatePeriodForSport('basketbol', 'Q1'));
    }
}

