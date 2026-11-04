<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sports\SportsProvenanceConflictEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportsProvenanceConflictEngineTest extends TestCase
{
    use RefreshDatabase;

    private SportsProvenanceConflictEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SportsProvenanceConflictEngine();
    }

    public function test_records_clean_observation_and_allows_publication(): void
    {
        $res = $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 101,
            snapshot: [
                'status' => 'live',
                'home_score' => 1,
                'away_score' => 0,
                'minute' => 35,
            ],
            revision: 1
        );

        $this->assertTrue($res['can_publish']);
        $this->assertEquals('clean', $res['status']);
        $this->assertNull($res['reason']);
        $this->assertGreaterThan(0, $res['record_id']);
    }

    public function test_triggers_hold_on_out_of_order_stale_revision(): void
    {
        // First record revision 2
        $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 102,
            snapshot: ['status' => 'live', 'home_score' => 1, 'away_score' => 0],
            revision: 2
        );

        // Arrives revision 1 (delayed packet)
        $delayedRes = $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 102,
            snapshot: ['status' => 'scheduled', 'home_score' => 0, 'away_score' => 0],
            revision: 1
        );

        $this->assertFalse($delayedRes['can_publish']);
        $this->assertEquals('hold', $delayedRes['status']);
        $this->assertStringContainsString('Out-of-order revision conflict', $delayedRes['reason']);
    }

    public function test_triggers_hold_on_unexplained_score_regression(): void
    {
        // Match was 2 - 1
        $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 103,
            snapshot: ['status' => 'live', 'home_score' => 2, 'away_score' => 1],
            revision: 5
        );

        // Next packet drops score to 1 - 1 without VAR cancellation explanation
        $badPacket = $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 103,
            snapshot: ['status' => 'live', 'home_score' => 1, 'away_score' => 1],
            revision: 6
        );

        $this->assertFalse($badPacket['can_publish']);
        $this->assertEquals('hold', $badPacket['status']);
        $this->assertStringContainsString('Score regression detected without VAR cancellation', $badPacket['reason']);
    }

    public function test_permits_score_reduction_when_var_cancellation_is_explicit(): void
    {
        // Match was 2 - 1
        $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 104,
            snapshot: ['status' => 'live', 'home_score' => 2, 'away_score' => 1],
            revision: 5
        );

        // VAR disallows goal: score returns to 1 - 1 with is_var_cancelled flag
        $varPacket = $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 104,
            snapshot: [
                'status' => 'live',
                'home_score' => 1,
                'away_score' => 1,
                'is_var_cancelled' => true,
                'var_reason' => 'Offside before goal',
            ],
            revision: 6
        );

        $this->assertTrue($varPacket['can_publish']);
        $this->assertEquals('clean', $varPacket['status']);
    }

    public function test_triggers_hold_on_status_regression_from_finished(): void
    {
        // Match finished
        $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 105,
            snapshot: ['status' => 'finished', 'home_score' => 3, 'away_score' => 2],
            revision: 10
        );

        // Later packet tries to revert status to live
        $revertPacket = $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 105,
            snapshot: ['status' => 'live', 'home_score' => 3, 'away_score' => 2],
            revision: 11
        );

        $this->assertFalse($revertPacket['can_publish']);
        $this->assertEquals('hold', $revertPacket['status']);
        $this->assertStringContainsString('Illegal status regression', $revertPacket['reason']);
    }

    public function test_triggers_hold_when_license_is_expired(): void
    {
        $expiredLicense = now()->subDay();

        $res = $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 106,
            snapshot: ['status' => 'live', 'home_score' => 1, 'away_score' => 0],
            revision: 1,
            licenseExpiresAt: $expiredLicense
        );

        $this->assertFalse($res['can_publish']);
        $this->assertEquals('hold', $res['status']);
        $this->assertStringContainsString('expired', $res['reason']);
    }

    public function test_manages_and_resolves_held_records(): void
    {
        $res = $this->engine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: 107,
            snapshot: ['status' => 'live', 'home_score' => 0, 'away_score' => 0],
            revision: 1,
            licenseExpiresAt: now()->subHours(2)
        );

        $heldList = $this->engine->getHeldRecords('fixture');
        $this->assertCount(1, $heldList);
        $this->assertEquals($res['record_id'], $heldList->first()->id);

        $resolved = $this->engine->resolveConflict($res['record_id'], 'resolved', 'License renewed with provider');
        $this->assertTrue($resolved);

        $this->assertCount(0, $this->engine->getHeldRecords('fixture'));
    }
}
