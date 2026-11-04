<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sports\ProviderEntityMapper;
use App\Models\Athlete;
use App\Models\Sport;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class ClubAthleteVenueProviderMappingTest extends TestCase
{
    use RefreshDatabase;

    private ProviderEntityMapper $mapper;
    private Team $team;
    private Athlete $athlete;
    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ProviderEntityMapper();

        $football = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);

        $this->venue = Venue::create([
            'slug' => 'ali-sami-yen',
            'name' => 'RAMS Park',
            'city' => 'İstanbul',
            'capacity' => 52280,
        ]);

        $this->team = Team::create([
            'sport_id' => $football->id,
            'slug' => 'galatasaray',
            'name' => 'Galatasaray',
            'venue_id' => $this->venue->id,
        ]);

        $this->athlete = Athlete::create([
            'sport_id' => $football->id,
            'current_team_id' => $this->team->id,
            'slug' => 'mauro-icardi',
            'name' => 'Mauro Icardi',
            'position' => 'FW',
            'shirt_number' => 9,
        ]);
    }

    public function test_can_link_and_resolve_provider_entity_mappings(): void
    {
        // Link Opta external ID to internal Team ID
        $this->mapper->linkEntity('opta', 'team', 't1024', $this->team->id, 'chk_12345');

        $resolved = $this->mapper->resolveEntityId('opta', 'team', 't1024');
        $this->assertSame($this->team->id, $resolved);

        // Different provider returns null
        $this->assertNull($this->mapper->resolveEntityId('sportradar', 'team', 't1024'));

        // Link Sportradar ID to athlete
        $this->mapper->linkEntity('sportradar', 'athlete', 'sr:player:9988', $this->athlete->id);
        $this->assertSame($this->athlete->id, $this->mapper->resolveEntityId('sportradar', 'athlete', 'sr:player:9988'));
    }

    public function test_invalid_entity_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mapper->linkEntity('opta', 'unsupported_entity', 'x99', 1);
    }

    public function test_athlete_multi_team_transfer_history_tracking(): void
    {
        $football = Sport::first();
        $psg = Team::create([
            'sport_id' => $football->id,
            'slug' => 'paris-saint-germain',
            'name' => 'Paris Saint-Germain',
        ]);

        // Record transfer from PSG to Galatasaray
        $transferId = $this->mapper->recordTransfer(
            athleteId: $this->athlete->id,
            toTeamId: $this->team->id,
            fromTeamId: $psg->id,
            transferDate: '2023-07-28',
            type: 'permanent',
            fee: 10000000.00,
            currency: 'EUR'
        );

        $this->assertGreaterThan(0, $transferId);

        $transfers = $this->mapper->getAthleteTransfers($this->athlete->id);
        $this->assertCount(1, $transfers);
        $this->assertSame('Paris Saint-Germain', $transfers[0]['from_team_name']);
        $this->assertSame('Galatasaray', $transfers[0]['to_team_name']);
        $this->assertEquals(10000000.00, (float) $transfers[0]['fee_amount']);
    }
}

