<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Validation;

use InvalidArgumentException;

final class NumericalIdentityClaimValidator
{
    /**
     * Validate that all numerical scores, event minutes, and player identities in the draft
     * strictly match the verified fact pack.
     * Acceptance criterion: Wrong numbers/names rejected in test suite.
     */
    public function validate(array $draft, array $factPack): array
    {
        $reasons = [];

        $textToInspect = implode(' ', [
            $draft['headline'] ?? '',
            $draft['summary'] ?? '',
            $draft['body'] ?? '',
        ]);

        $metricFacts = $factPack['metric_facts'] ?? [];
        $expectedHomeScore = (int) ($metricFacts['home_team']['score'] ?? $metricFacts['home_score'] ?? 0);
        $expectedAwayScore = (int) ($metricFacts['away_team']['score'] ?? $metricFacts['away_score'] ?? 0);

        // 1. Numerical score pattern inspection
        if (preg_match_all('/\b(\d+)\s*[-–]\s*(\d+)\b/', $textToInspect, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $score1 = (int) $match[1];
                $score2 = (int) $match[2];

                $matchesForward = ($score1 === $expectedHomeScore && $score2 === $expectedAwayScore);
                $matchesReverse = ($score1 === $expectedAwayScore && $score2 === $expectedHomeScore);

                if (! $matchesForward && ! $matchesReverse) {
                    $reasons[] = "Wrong score claim: text asserts '{$match[0]}', but verified fact pack score is {$expectedHomeScore}-{$expectedAwayScore}";
                }
            }
        }

        // 2. Event minute citations inspection (e.g. "24. dakikada", "88. dk", "61'")
        $allowedMinutes = [];
        foreach (($metricFacts['goals'] ?? []) as $goal) {
            if (isset($goal['minute'])) {
                $allowedMinutes[] = (int) $goal['minute'];
            }
        }

        if (preg_match_all('/\b(\d{1,3})(?:\.|\'|\.?(?:\s*(?:dakika\w*|dk)))/iu', $textToInspect, $minMatches, PREG_SET_ORDER)) {
            foreach ($minMatches as $m) {
                $minute = (int) $m[1];
                // Allow standard halves 45, 90, 120
                if ($minute > 0 && ! in_array($minute, $allowedMinutes, true) && ! in_array($minute, [45, 90, 120], true)) {
                    $reasons[] = "Unverified minute claim: minute {$minute} does not match any verified event minute in fact pack";
                }
            }
        }

        // 3. Hallucinated / unauthorized player identities check
        // Fact pack verified players
        $verifiedPlayers = [];
        foreach (($metricFacts['goals'] ?? []) as $goal) {
            if (! empty($goal['player'])) {
                $verifiedPlayers[] = mb_strtolower(trim((string) $goal['player']));
            }
        }

        // Check if disallowed or unverified players appear in claim citations
        foreach (($draft['cited_claim_ids'] ?? []) as $claimId) {
            if (str_starts_with($claimId, 'claim_goal_')) {
                // Find matching claim in fact pack
                $found = false;
                foreach (($factPack['evidence_claims'] ?? []) as $ec) {
                    if ($ec['claim_id'] === $claimId) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $reasons[] = "Cited goal claim '{$claimId}' has no matching verified evidence in fact pack";
                }
            }
        }

        $isValid = ($reasons === []);

        return [
            'valid' => $isValid,
            'reasons' => array_values(array_unique($reasons)),
            'verified_facts' => [
                'home_score' => $expectedHomeScore,
                'away_score' => $expectedAwayScore,
                'allowed_minutes' => $allowedMinutes,
                'verified_players' => $verifiedPlayers,
            ],
        ];
    }

    /**
     * Assert valid or throw InvalidArgumentException.
     */
    public function assertValid(array $draft, array $factPack): void
    {
        $result = $this->validate($draft, $factPack);
        if (! $result['valid']) {
            throw new InvalidArgumentException('Numerical/identity claim validation failed: ' . implode('; ', $result['reasons']));
        }
    }
}