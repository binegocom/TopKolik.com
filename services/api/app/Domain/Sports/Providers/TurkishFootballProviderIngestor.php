<?php

declare(strict_types=1);

namespace App\Domain\Sports\Providers;

use App\Domain\Sports\FixtureStateMachine;
use App\Domain\Sports\ProviderEntityMapper;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Sport;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TurkishFootballProviderIngestor
{
    public const PROVIDER_NAME = 'tff_licensed_feed';

    public function __construct(
        private readonly ProviderEntityMapper $mapper,
        private readonly FixtureStateMachine $stateMachine = new FixtureStateMachine(),
    ) {}

    /**
     * Ingest or update Turkey prioritized competitions (Süper Lig, 1. Lig, Türkiye Kupası).
     */
    public function ingestTurkishCompetition(array $payload): Competition
    {
        $sport = Sport::firstOrCreate(['slug' => 'futbol'], ['name' => 'Futbol']);

        $slug = $payload['slug'] ?? 'super-lig';
        $name = $payload['name'] ?? 'Trendyol Süper Lig';
        $providerRef = $payload['provider_ref'] ?? 'tff_comp_01';

        $competition = Competition::updateOrCreate(
            ['sport_id' => $sport->id, 'slug' => $slug],
            [
                'name' => $name,
                'country_code' => 'TR',
                'provider_ref' => $providerRef,
            ]
        );

        $this->mapper->linkEntity(self::PROVIDER_NAME, 'competition', $providerRef, $competition->id);

        return $competition;
    }

    /**
     * Ingest Turkish club with venue and branding.
     */
    public function ingestTurkishTeam(Competition $competition, array $teamPayload): Team
    {
        $sportId = $competition->sport_id;
        $slug = $teamPayload['slug'];
        $name = $teamPayload['name'];
        $providerRef = $teamPayload['provider_ref'];

        $team = Team::updateOrCreate(
            ['sport_id' => $sportId, 'slug' => $slug],
            [
                'name' => $name,
                'short_name' => $teamPayload['short_name'] ?? null,
                'primary_color' => $teamPayload['primary_color'] ?? '#0B5D3B',
                'secondary_color' => $teamPayload['secondary_color'] ?? '#7CFF4D',
            ]
        );

        $this->mapper->linkEntity(self::PROVIDER_NAME, 'team', $providerRef, $team->id);

        return $team;
    }

    /**
     * Ingest Turkish fixture with status and revision guard.
     */
    public function ingestTurkishFixture(Competition $competition, Season $season, array $fixturePayload): Fixture
    {
        $homeId = $this->mapper->resolveEntityId(self::PROVIDER_NAME, 'team', $fixturePayload['home_team_ref']);
        $awayId = $this->mapper->resolveEntityId(self::PROVIDER_NAME, 'team', $fixturePayload['away_team_ref']);

        if (! $homeId || ! $awayId) {
            throw new InvalidArgumentException('Unmapped home or away team for Turkish fixture payload');
        }

        $startsAt = $fixturePayload['starts_at'];
        $status = $fixturePayload['status'] ?? 'scheduled';
        $providerRef = $fixturePayload['provider_fixture_id'];

        $fixture = Fixture::updateOrCreate(
            [
                'competition_id' => $competition->id,
                'season_id' => $season->id,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
            ],
            [
                'starts_at' => $startsAt,
                'status' => $status,
                'home_score' => $fixturePayload['home_score'] ?? null,
                'away_score' => $fixturePayload['away_score'] ?? null,
                'first_scoring_team' => $fixturePayload['first_scoring_team'] ?? null,
                'source_revision' => $fixturePayload['source_revision'] ?? 1,
            ]
        );

        $this->mapper->linkEntity(self::PROVIDER_NAME, 'fixture', $providerRef, $fixture->id);

        return $fixture;
    }

    /**
     * Ingest live revision update with monotonic revision check and state machine validation.
     */
    public function ingestLiveRevision(Fixture $fixture, array $revisionPayload): array
    {
        $incomingRevision = (int) ($revisionPayload['source_revision'] ?? 1);
        $currentRevision = (int) ($fixture->source_revision ?? 0);

        if ($incomingRevision < $currentRevision) {
            return [
                'applied' => false,
                'reason' => "Stale revision rejected (incoming: {$incomingRevision}, current: {$currentRevision})",
                'fixture' => $fixture,
            ];
        }

        $newStatus = $revisionPayload['status'] ?? $fixture->status;
        if ($newStatus !== $fixture->status) {
            $this->stateMachine->transition($fixture, $newStatus);
        }

        $fixture->home_score = $revisionPayload['home_score'] ?? $fixture->home_score;
        $fixture->away_score = $revisionPayload['away_score'] ?? $fixture->away_score;
        $fixture->source_revision = $incomingRevision;
        if (isset($revisionPayload['first_scoring_team'])) {
            $fixture->first_scoring_team = $revisionPayload['first_scoring_team'];
        }
        $fixture->save();

        if (! empty($revisionPayload['events'])) {
            foreach ($revisionPayload['events'] as $event) {
                DB::table('fixture_events')->updateOrInsert(
                    [
                        'fixture_id' => $fixture->id,
                        'event_type' => $event['event_type'],
                        'minute' => $event['minute'],
                        'team_id' => $event['team_id'] ?? null,
                    ],
                    [
                        'extra_minute' => $event['extra_minute'] ?? null,
                        'period' => $event['period'] ?? '1h',
                        'player_name' => $event['player_name'] ?? null,
                        'detail' => $event['detail'] ?? null,
                        'source_provenance' => self::PROVIDER_NAME,
                        'is_var_cancelled' => $event['is_var_cancelled'] ?? false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        return [
            'applied' => true,
            'reason' => 'Revision successfully applied',
            'fixture' => $fixture->fresh(),
        ];
    }
}
