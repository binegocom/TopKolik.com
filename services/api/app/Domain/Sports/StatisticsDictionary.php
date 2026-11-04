<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use InvalidArgumentException;

final class StatisticsDictionary
{
    private const METRICS_BY_SPORT = [
        'futbol' => [
            'possession_pct' => ['unit' => '%', 'type' => 'percentage', 'min' => 0, 'max' => 100],
            'shots_total' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'shots_on_target' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'expected_goals' => ['unit' => 'xG', 'type' => 'float', 'min' => 0.0],
            'pass_accuracy_pct' => ['unit' => '%', 'type' => 'percentage', 'min' => 0, 'max' => 100],
            'corners' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'fouls' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'yellow_cards' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'red_cards' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'offsides' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'saves' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
        ],
        'basketbol' => [
            'points' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'field_goals_pct' => ['unit' => '%', 'type' => 'percentage', 'min' => 0, 'max' => 100],
            'three_pointers_pct' => ['unit' => '%', 'type' => 'percentage', 'min' => 0, 'max' => 100],
            'free_throws_pct' => ['unit' => '%', 'type' => 'percentage', 'min' => 0, 'max' => 100],
            'rebounds_offensive' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'rebounds_defensive' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'assists' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'steals' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'blocks' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'turnovers' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'fouls_personal' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
        ],
        'voleybol' => [
            'sets_won' => ['unit' => 'count', 'type' => 'integer', 'min' => 0, 'max' => 3],
            'points_total' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'ace_serves' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'attack_points' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'block_points' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
            'opponent_errors' => ['unit' => 'count', 'type' => 'integer', 'min' => 0],
        ],
    ];

    public function getAllowedMetrics(string $sportSlug): array
    {
        $normalized = mb_strtolower(trim($sportSlug));
        if (! isset(self::METRICS_BY_SPORT[$normalized])) {
            throw new InvalidArgumentException("Unknown sport for statistics dictionary: {$sportSlug}");
        }

        return self::METRICS_BY_SPORT[$normalized];
    }

    public function validateMetric(string $sportSlug, string $metricKey, int|float $value): void
    {
        $allowed = $this->getAllowedMetrics($sportSlug);

        if (! isset($allowed[$metricKey])) {
            throw new InvalidArgumentException(
                "Metric '{$metricKey}' is not allowed for sport '{$sportSlug}'. Prevents cross-sport stat conflation."
            );
        }

        $meta = $allowed[$metricKey];

        if (isset($meta['min']) && $value < $meta['min']) {
            throw new InvalidArgumentException("Metric '{$metricKey}' value {$value} is below minimum {$meta['min']}");
        }

        if (isset($meta['max']) && $value > $meta['max']) {
            throw new InvalidArgumentException("Metric '{$metricKey}' value {$value} exceeds maximum {$meta['max']}");
        }
    }

    public function sanitizeMetrics(string $sportSlug, array $rawMetrics): array
    {
        $allowed = $this->getAllowedMetrics($sportSlug);
        $clean = [];

        foreach ($rawMetrics as $key => $val) {
            if (isset($allowed[$key])) {
                $this->validateMetric($sportSlug, (string) $key, $val);
                $clean[$key] = $val;
            }
        }

        return $clean;
    }
}

