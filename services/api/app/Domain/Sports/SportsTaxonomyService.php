<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use App\Models\Competition;
use App\Models\Sport;
use InvalidArgumentException;

final class SportsTaxonomyService
{
    /**
     * Official taxonomy metadata definitions for licensed sports coverage.
     */
    private const TAXONOMY = [
        'futbol' => [
            'name' => 'Futbol',
            'code' => 'SOC',
            'federation' => 'FIFA',
            'scoring_unit' => 'goals',
            'periods' => ['1H', '2H', 'ET1', 'ET2', 'PEN'],
            'standard_minutes' => 90,
            'coverage_tiers' => [
                'tier_1' => ['Trendyol Süper Lig', 'Premier League', 'La Liga', 'Serie A', 'Bundesliga', 'UEFA Champions League'],
                'tier_2' => ['Trendyol 1. Lig', 'Ziraat Türkiye Kupası', 'UEFA Europa League', 'UEFA Conference League'],
            ],
            'capabilities' => ['live_score', 'timeline', 'lineups', 'team_stats', 'player_stats', 'xG', 'var_events'],
        ],
        'basketbol' => [
            'name' => 'Basketbol',
            'code' => 'BKB',
            'federation' => 'FIBA',
            'scoring_unit' => 'points',
            'periods' => ['Q1', 'Q2', 'Q3', 'Q4', 'OT'],
            'standard_minutes' => 40,
            'coverage_tiers' => [
                'tier_1' => ['Türkiye Sigorta Basketbol Süper Ligi', 'EuroLeague', 'NBA'],
                'tier_2' => ['EuroCup', 'FIBA Champions League', 'Türkiye Kupası'],
            ],
            'capabilities' => ['live_score', 'box_score', 'play_by_play', 'player_stats', 'quarter_scores'],
        ],
        'voleybol' => [
            'name' => 'Voleybol',
            'code' => 'VBL',
            'federation' => 'FIVB',
            'scoring_unit' => 'sets_and_points',
            'periods' => ['S1', 'S2', 'S3', 'S4', 'S5'],
            'standard_minutes' => 120,
            'coverage_tiers' => [
                'tier_1' => ['Vodafone Sultanlar Ligi', 'AXA Sigorta Efeler Ligi', 'CEV Champions League'],
                'tier_2' => ['Kupa Voley', 'CEV Cup'],
            ],
            'capabilities' => ['live_score', 'set_scores', 'player_stats', 'attack_efficiency'],
        ],
    ];

    public function getSupportedSports(): array
    {
        return array_keys(self::TAXONOMY);
    }

    public function getSportMetadata(string $sportSlug): array
    {
        $normalized = mb_strtolower(trim($sportSlug));
        if (! isset(self::TAXONOMY[$normalized])) {
            throw new InvalidArgumentException("Unsupported sport in Topkolik taxonomy: {$sportSlug}");
        }

        return self::TAXONOMY[$normalized];
    }

    public function validatePeriodForSport(string $sportSlug, string $period): bool
    {
        $meta = $this->getSportMetadata($sportSlug);
        return in_array($period, $meta['periods'], true);
    }

    public function mapCompetitionToTaxonomy(Competition $competition, string $tier = 'tier_1'): array
    {
        $sport = $competition->sport;
        $sportSlug = $sport ? $sport->slug : 'futbol';
        $meta = $this->getSportMetadata($sportSlug);

        $isTier1 = in_array($competition->name, $meta['coverage_tiers']['tier_1'] ?? [], true);
        $isTier2 = in_array($competition->name, $meta['coverage_tiers']['tier_2'] ?? [], true);

        return [
            'competition_id' => $competition->id,
            'competition_slug' => $competition->slug,
            'sport' => $meta['name'],
            'sport_code' => $meta['code'],
            'resolved_tier' => $isTier1 ? 'tier_1' : ($isTier2 ? 'tier_2' : 'standard'),
            'scoring_unit' => $meta['scoring_unit'],
            'allowed_periods' => $meta['periods'],
            'capabilities' => $meta['capabilities'],
        ];
    }
}

