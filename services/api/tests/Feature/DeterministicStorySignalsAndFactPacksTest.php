<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Editorial\FactPackBuilder;
use App\Domain\Editorial\PublicationGuard;
use App\Domain\Editorial\StorySignalDetector;
use App\Domain\Sports\SportsProvenanceConflictEngine;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Sport;
use App\Models\Team;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class DeterministicStorySignalsAndFactPacksTest extends TestCase
{
    use RefreshDatabase;

    private StorySignalDetector $signalDetector;
    private FactPackBuilder $factPackBuilder;
    private SportsProvenanceConflictEngine $conflictEngine;
    private PublicationGuard $publicationGuard;

    private Fixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conflictEngine = new SportsProvenanceConflictEngine();
        $this->signalDetector = new StorySignalDetector($this->conflictEngine);
        $this->factPackBuilder = new FactPackBuilder();
        $this->publicationGuard = new PublicationGuard();

        $sport = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);
        $comp = Competition::create(['sport_id' => $sport->id, 'slug' => 'super-lig', 'name' => 'Trendyol Süper Lig']);
        $season = Season::create(['competition_id' => $comp->id, 'label' => '2026-2027', 'is_current' => true]);

        $home = Team::create(['sport_id' => $sport->id, 'slug' => 'galatasaray', 'name' => 'Galatasaray']);
        $away = Team::create(['sport_id' => $sport->id, 'slug' => 'fenerbahce', 'name' => 'Fenerbahçe']);

        $this->fixture = Fixture::create([
            'competition_id' => $comp->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->subHours(2),
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
            'source_revision' => 8,
        ]);
    }

    public function test_emits_deterministic_story_signal_for_finished_match(): void
    {
        $res = $this->signalDetector->detectFinalMatchResult(
            fixture: $this->fixture,
            providerName: 'tff_licensed_feed',
            observationSnapshot: [
                'status' => 'finished',
                'home_score' => 2,
                'away_score' => 1,
            ],
            revision: 8
        );

        $this->assertTrue($res['emitted']);
        $this->assertEquals('final_match_result', $res['signal_type']);
        $this->assertNotNull($res['signal_id']);

        $record = DB::table('editorial_story_signals')->where('id', $res['signal_id'])->first();
        $this->assertNotNull($record);
        $this->assertEquals('emitted', $record->status);
        $this->assertEquals('fixture', $record->entity_type);
        $this->assertEquals($this->fixture->id, $record->entity_id);
    }

    public function test_refuses_story_emission_for_provisional_live_or_scheduled_match(): void
    {
        $this->fixture->status = 'live';
        $this->fixture->save();

        $res = $this->signalDetector->detectFinalMatchResult(
            fixture: $this->fixture,
            providerName: 'tff_licensed_feed',
            observationSnapshot: ['status' => 'live', 'home_score' => 1, 'away_score' => 0]
        );

        $this->assertFalse($res['emitted']);
        $this->assertStringContainsString('Provisional fixture state', $res['reason']);
        $this->assertNull($res['signal_id']);
    }

    public function test_refuses_story_emission_for_missing_scores(): void
    {
        $this->fixture->home_score = null;
        $this->fixture->save();

        $res = $this->signalDetector->detectFinalMatchResult(
            fixture: $this->fixture,
            providerName: 'tff_licensed_feed',
            observationSnapshot: ['status' => 'finished']
        );

        $this->assertFalse($res['emitted']);
        $this->assertStringContainsString('Missing final score', $res['reason']);
    }

    public function test_refuses_story_emission_when_fixture_is_held_in_conflict(): void
    {
        // Record conflicting observation in conflict engine
        $this->conflictEngine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: $this->fixture->id,
            snapshot: ['status' => 'finished', 'home_score' => 2, 'away_score' => 1],
            revision: 10
        );

        // Score regression without VAR trips hold
        $this->conflictEngine->recordObservation(
            providerName: 'tff_licensed_feed',
            entityType: 'fixture',
            entityId: $this->fixture->id,
            snapshot: ['status' => 'finished', 'home_score' => 1, 'away_score' => 1],
            revision: 11
        );

        $res = $this->signalDetector->detectFinalMatchResult(
            fixture: $this->fixture,
            providerName: 'tff_licensed_feed',
            observationSnapshot: ['status' => 'finished', 'home_score' => 1, 'away_score' => 1],
            revision: 11
        );

        $this->assertFalse($res['emitted']);
        $this->assertStringContainsString('held in provenance conflict', $res['reason']);
    }

    public function test_emits_fixture_change_and_official_statement_signals(): void
    {
        $this->fixture->status = 'postponed';
        $this->fixture->save();

        $changeRes = $this->signalDetector->detectFixtureChange(
            fixture: $this->fixture,
            changeType: 'postponed_weather',
            providerName: 'tff_licensed_feed',
            changeDetails: ['weather_reason' => 'Yoğun kar yağışı']
        );

        $this->assertTrue($changeRes['emitted']);
        $this->assertEquals('fixture_change', $changeRes['signal_type']);

        // Official statement
        $statementRes = $this->signalDetector->detectOfficialAnnouncement(
            providerName: 'club_official',
            entityType: 'team',
            entityId: $this->fixture->home_team_id,
            announcementPayload: [
                'headline' => 'Kulübümüzden Açıklama',
                'body' => 'Derbi hazırlıklarımız tamamlanmıştır.',
            ]
        );

        $this->assertTrue($statementRes['emitted']);
        $this->assertEquals('official_statement', $statementRes['signal_type']);
    }

    public function test_builds_evidence_bound_fact_pack_and_passes_publication_guard(): void
    {
        $signalRes = $this->signalDetector->detectFinalMatchResult(
            fixture: $this->fixture,
            providerName: 'tff_licensed_feed',
            observationSnapshot: [
                'status' => 'finished',
                'home_score' => 2,
                'away_score' => 1,
            ],
            revision: 8
        );

        $licenseExpiry = now()->addMonths(6);

        $factPack = $this->factPackBuilder->buildMatchFactPack(
            storySignalId: $signalRes['signal_id'],
            fixture: $this->fixture,
            licensedSourceRef: 'tff_licensed_feed:obs:101:rev8',
            licenseExpiresAt: $licenseExpiry,
            factsPayload: [
                'source_snapshot_id' => 'snap_derby_final_101',
                'home_team_name' => 'Galatasaray',
                'away_team_name' => 'Fenerbahçe',
                'home_score' => 2,
                'away_score' => 1,
                'goals' => [
                    ['player' => 'Mauro Icardi', 'minute' => 24, 'team' => 'home'],
                    ['player' => 'Edin Džeko', 'minute' => 61, 'team' => 'away'],
                    ['player' => 'Victor Osimhen', 'minute' => 88, 'team' => 'home'],
                ],
                'statistics' => [
                    'possession_home' => 54,
                    'possession_away' => 46,
                    'shots_home' => 14,
                    'shots_away' => 9,
                ],
            ]
        );

        $this->assertNotNull($factPack['fact_pack_id']);
        $this->assertEquals('1.0.0', $factPack['schema_version']);
        $this->assertTrue($factPack['is_verified']);
        $this->assertCount(6, $factPack['evidence_claims']); // final_score + home_score + away_score + 3 goals

        // Verify that every claim points to exact source evidence snapshot
        foreach ($factPack['evidence_claims'] as $claim) {
            $this->assertEquals('snap_derby_final_101', $claim['source_snapshot_id']);
            $this->assertTrue($claim['verified']);
        }

        // Convert to PublicationCandidate and verify against PublicationGuard
        $candidate = $this->factPackBuilder->toPublicationCandidate(
            factPack: $factPack,
            headline: 'Derbide Kazanan Galatasaray: 2 - 1',
            body: 'Trendyol Süper Lig derbisinde Galatasaray, Fenerbahçe’yi Icardi ve Osimhen’in golleriyle 2-1 mağlup etti.',
            publishingEnabled: true
        );

        $decision = $this->publicationGuard->evaluate($candidate, new DateTimeImmutable());

        $this->assertTrue($decision->allowed);
        $this->assertEmpty($decision->reasons);
    }

    public function test_rejects_fact_pack_when_evidence_snapshot_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source_snapshot_id is required to bind evidence');

        $this->factPackBuilder->buildMatchFactPack(
            storySignalId: 1,
            fixture: $this->fixture,
            licensedSourceRef: 'tff_feed',
            licenseExpiresAt: now()->addDay(),
            factsPayload: [
                'home_score' => 2,
                'away_score' => 1,
            ]
        );
    }
}
