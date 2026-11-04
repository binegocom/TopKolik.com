<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Validation;

use DateTimeImmutable;
use DateTimeInterface;

final class HighRiskEditorialReviewGate
{
    /**
     * Evaluate draft and source metadata for high-risk flags.
     * Acceptance criterion: No high-risk story auto-publishes.
     */
    public function evaluate(array $draft, array $factPack, ?DateTimeInterface $now = null): array
    {
        $currentDate = $now ?? now();
        $reasons = [];

        $fullText = mb_strtolower(implode(' ', [
            $draft['headline'] ?? '',
            $draft['summary'] ?? '',
            $draft['body'] ?? '',
        ]));

        // 1. Rumor & Unverified Speculation Keywords
        $rumorKeywords = [
            'iddia edildi',
            'iddialara göre',
            'söylenti',
            'dedikodu',
            'ileri sürüldü',
            'kulislerde konuşulan',
            'resmiyet kazanmadı',
        ];

        foreach ($rumorKeywords as $keyword) {
            if (str_contains($fullText, $keyword)) {
                $reasons[] = "RUMOR_DETECTED: Text contains speculative phrase '{$keyword}'";
            }
        }

        // 2. Unverified High-Risk Injury / Medical Claims
        $injuryKeywords = [
            'bağları koptu',
            'sezonu kapattı',
            'ameliyat masasına yatacak',
            'kalp rahatsızlığı',
        ];

        foreach ($injuryKeywords as $keyword) {
            if (str_contains($fullText, $keyword) && empty($factPack['is_official_medical_bulletin'])) {
                $reasons[] = "UNVERIFIED_MEDICAL_RISK: Serious injury claim '{$keyword}' without official medical bulletin";
            }
        }

        // 3. Match-Fixing / Defamation / Scandal Allegations
        $scandalKeywords = [
            'şike',
            'hakem skandalı',
            'rüşvet',
            'maç satıldı',
            'tff istifa',
            'komplo',
        ];

        foreach ($scandalKeywords as $keyword) {
            if (str_contains($fullText, $keyword)) {
                $reasons[] = "SCANDAL_ALLEGATION_RISK: Prohibited inflammatory claim '{$keyword}'";
            }
        }

        // 4. Rights & License Expiry Gate
        if (! empty($factPack['license_expires_at'])) {
            $expiry = new DateTimeImmutable((string) $factPack['license_expires_at']);
            if ($expiry->getTimestamp() <= $currentDate->getTimestamp()) {
                $reasons[] = 'LICENSE_EXPIRED: Licensed source rights have expired';
            }
        } else {
            $reasons[] = 'RIGHTS_GAP: Fact pack lacks mandatory license expiry timestamp';
        }

        // 5. Source Provenance Contradictions
        if (! empty($factPack['has_conflict']) || ($factPack['is_verified'] ?? true) === false) {
            $reasons[] = 'CONTRADICTED_SOURCE: Fact pack carries open source contradictions';
        }

        $isHighRisk = ($reasons !== []);

        return [
            'is_high_risk' => $isHighRisk,
            'can_auto_publish' => ! $isHighRisk,
            'quarantine_reasons' => $reasons,
            'recommendation' => $isHighRisk ? 'quarantine' : 'proceed_to_publish',
        ];
    }
}
