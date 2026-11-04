<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class SportsEntitlementGuard
{
    /**
     * Whitelist of entitled competitions per sport with license validity windows.
     */
    private const ENTITLEMENT_CATALOG = [
        'futbol' => [
            'allowed_providers' => ['tff_licensed_feed', 'licensed_sports_feed', 'uefa_official_feed'],
            'licensed_competitions' => [
                'super-lig' => ['tier' => 'tier_1', 'license_expires_at' => '2028-06-30'],
                'trendyol-1-lig' => ['tier' => 'tier_2', 'license_expires_at' => '2028-06-30'],
                'turkiye-kupasi' => ['tier' => 'tier_2', 'license_expires_at' => '2028-06-30'],
                'champions-league' => ['tier' => 'tier_1', 'license_expires_at' => '2027-06-30'],
                'premier-league' => ['tier' => 'tier_1', 'license_expires_at' => '2027-06-30'],
            ],
            'capabilities' => ['live_score', 'timeline', 'lineups', 'team_stats', 'player_stats', 'xG', 'var_events'],
        ],
        'basketbol' => [
            'allowed_providers' => ['licensed_multisport_feed', 'fiba_feed', 'euroleague_feed'],
            'licensed_competitions' => [
                'bsl' => ['tier' => 'tier_1', 'license_expires_at' => '2028-06-30'],
                'euroleague' => ['tier' => 'tier_1', 'license_expires_at' => '2027-06-30'],
                'nba' => ['tier' => 'tier_1', 'license_expires_at' => '2026-12-31'],
            ],
            'capabilities' => ['live_score', 'box_score', 'play_by_play', 'player_stats', 'quarter_scores'],
        ],
        'voleybol' => [
            'allowed_providers' => ['licensed_multisport_feed', 'tvf_official_feed'],
            'licensed_competitions' => [
                'sultanlar-ligi' => ['tier' => 'tier_1', 'license_expires_at' => '2028-06-30'],
                'efeler-ligi' => ['tier' => 'tier_1', 'license_expires_at' => '2028-06-30'],
            ],
            'capabilities' => ['live_score', 'set_scores', 'player_stats', 'attack_efficiency'],
        ],
    ];

    /**
     * Verify whether a sport, provider, and competition slug is entitled.
     * Throws an exception and triggers an alert if unentitled.
     */
    public function authorizeIngestion(string $sportSlug, string $providerName, string $competitionSlug): array
    {
        $normalizedSport = mb_strtolower(trim($sportSlug));

        if (! isset(self::ENTITLEMENT_CATALOG[$normalizedSport])) {
            $this->triggerAlert('unentitled_sport', "Sport '{$sportSlug}' is not licensed for ingestion", [
                'sport' => $sportSlug,
                'provider' => $providerName,
                'competition' => $competitionSlug,
            ]);

            throw new RuntimeException("Sport '{$sportSlug}' is not entitled for licensed ingestion.");
        }

        $sportConfig = self::ENTITLEMENT_CATALOG[$normalizedSport];

        if (! in_array($providerName, $sportConfig['allowed_providers'], true)) {
            $this->triggerAlert('unauthorized_provider', "Provider '{$providerName}' is not authorized for {$sportSlug}", [
                'sport' => $sportSlug,
                'provider' => $providerName,
                'competition' => $competitionSlug,
            ]);

            throw new RuntimeException("Provider '{$providerName}' is not authorized for sport '{$sportSlug}'.");
        }

        if (! isset($sportConfig['licensed_competitions'][$competitionSlug])) {
            $this->triggerAlert('unentitled_competition', "Competition '{$competitionSlug}' is unentitled under {$sportSlug}", [
                'sport' => $sportSlug,
                'provider' => $providerName,
                'competition' => $competitionSlug,
            ]);

            throw new RuntimeException("Competition '{$competitionSlug}' is not entitled under sport '{$sportSlug}'.");
        }

        $compMeta = $sportConfig['licensed_competitions'][$competitionSlug];

        return [
            'authorized' => true,
            'sport' => $normalizedSport,
            'competition_slug' => $competitionSlug,
            'tier' => $compMeta['tier'],
            'license_expires_at' => $compMeta['license_expires_at'],
            'capabilities' => $sportConfig['capabilities'],
        ];
    }

    /**
     * Check if a specific capability is entitled for a sport.
     */
    public function isCapabilityEntitled(string $sportSlug, string $capability): bool
    {
        $normalized = mb_strtolower(trim($sportSlug));
        $capabilities = self::ENTITLEMENT_CATALOG[$normalized]['capabilities'] ?? [];

        return in_array($capability, $capabilities, true);
    }

    /**
     * Trigger security and operations alerting for license or entitlement violations.
     */
    public function triggerAlert(string $alertType, string $message, array $context = []): void
    {
        Log::channel('single')->warning("[SPORTS_ENTITLEMENT_ALERT] {$alertType}: {$message}", $context);
    }
}
