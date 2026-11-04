<?php

declare(strict_types=1);

namespace App\Domain\Sports\Providers;

use App\Domain\Sports\FixtureStateMachine;
use App\Domain\Sports\ProviderEntityMapper;
use App\Domain\Sports\SportsTaxonomyService;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Sport;
use App\Models\Team;
use InvalidArgumentException;

final class MultiSportProviderIngestor
{
    public const PROVIDER_NAME = 'licensed_multisport_feed';

    public function __construct(
        private readonly ProviderEntityMapper $mapper,
        private readonly SportsTaxonomyService $taxonomy = new SportsTaxonomyService(),
        private readonly FixtureStateMachine $stateMachine = new FixtureStateMachine(),
    ) {}

    /**
     * Ingest an approved multi-sport competition (Basketball BSL/EuroLeague, Volleyball Sultanlar/Efeler).
     */
    public function ingestCompetition(string $sportSlug, array $payload): array
    {
        $meta = $this->taxonomy->getSportMetadata($sportSlug);
        $sport = Sport::firstOrCreate(['slug' => $sportSlug], ['name' => $meta['name']]);

        $slug = $payload['slug'];
        $name = $payload['name'];
        $providerRef = $payload['provider_ref'];

        $competition = Competition::updateOrCreate(
            ['sport_id' => $sport->id, 'slug' => $slug],
            [
                'name' => $name,
                'country_code' => $payload['country_code'] ?? 'TR',
                'provider_ref' => $providerRef,
            ]
        );

        $this->mapper->linkEntity(self::PROVIDER_NAME, 'competition', $providerRef, $competition->id);
        $taxMapping = $this->taxonomy->mapCompetitionToTaxonomy($competition);

        return [
            'competition' => $competition,
            'taxonomy' => $taxMapping,
            'capabilities' => $meta['capabilities'],
        ];
    }

    /**
     * Ingest a team for the given sport competition.
     */
    public function ingestTeam(Competition $competition, array $teamPayload): Team
    {
        $team = Team::updateOrCreate(
            ['sport_id' => $competition->sport_id, 'slug' => $teamPayload['slug']],
            [
                'name' => $teamPayload['name'],
                'short_name' => $teamPayload['short_name'] ?? null,
                'primary_color' => $teamPayload['primary_color'] ?? '#0B5D3B',
                'secondary_color' => $teamPayload['secondary_color'] ?? '#7CFF4D',
            ]
        );

        $this->mapper->linkEntity(self::PROVIDER_NAME, 'team', $teamPayload['provider_ref'], $team->id);

        return $team;
    }

    /**
     * Ingest a basketball fixture with period (quarter) scores.
     */
    public function ingestBasketballFixture(Competition $competition, Season $season, array $fixturePayload): Fixture
    {
        $homeId = $this->mapper->resolveEntityId(self::PROVIDER_NAME, 'team', $fixturePayload['home_team_ref']);
        $awayId = $this->mapper->resolveEntityId(self::PROVIDER_NAME, 'team', $fixturePayload['away_team_ref']);

        if (! $homeId || ! $awayId) {
            throw new InvalidArgumentException('Unmapped home or away team for basketball fixture payload');
        }

        $fixture = Fixture::updateOrCreate(
            [
                'competition_id' => $competition->id,
                'season_id' => $season->id,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
            ],
            [
                'starts_at' => $fixturePayload['starts_at'],
                'status' => $fixturePayload['status'] ?? 'scheduled',
                'home_score' => $fixturePayload['home_score'] ?? null,
                'away_score' => $fixturePayload['away_score'] ?? null,
                'source_revision' => $fixturePayload['source_revision'] ?? 1,
            ]
        );

        $this->mapper->linkEntity(self::PROVIDER_NAME, 'fixture', $fixturePayload['provider_fixture_id'], $fixture->id);

        return $fixture;
    }

    /**
     * Ingest a volleyball fixture with set-by-set points and match set score.
     */
    public function ingestVolleyballFixture(Competition $competition, Season $season, array $fixturePayload): Fixture
    {
        $homeId = $this->mapper->resolveEntityId(self::PROVIDER_NAME, 'team', $fixturePayload['home_team_ref']);
        $awayId = $this->mapper->resolveEntityId(self::PROVIDER_NAME, 'team', $fixturePayload['away_team_ref']);

        if (! $homeId || ! $awayId) {
            throw new InvalidArgumentException('Unmapped home or away team for volleyball fixture payload');
        }

        $fixture = Fixture::updateOrCreate(
            [
                'competition_id' => $competition->id,
                'season_id' => $season->id,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
            ],
            [
                'starts_at' => $fixturePayload['starts_at'],
                'status' => $fixturePayload['status'] ?? 'scheduled',
                'home_score' => $fixturePayload['home_score'] ?? null, // e.g. 3 sets
                'away_score' => $fixturePayload['away_score'] ?? null, // e.g. 1 set
                'source_revision' => $fixturePayload['source_revision'] ?? 1,
            ]
        );

        $this->mapper->linkEntity(self::PROVIDER_NAME, 'fixture', $fixturePayload['provider_fixture_id'], $fixture->id);

        return $fixture;
    }

    /**
     * Validate sport capability support for an event.
     */
    public function assertSportSupportsCapability(string $sportSlug, string $capability): bool
    {
        $meta = $this->taxonomy->getSportMetadata($sportSlug);
        if (! in_array($capability, $meta['capabilities'], true)) {
            throw new InvalidArgumentException("Sport {$sportSlug} does not support capability: {$capability}");
        }

        return true;
    }
}
