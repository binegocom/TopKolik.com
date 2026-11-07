<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Transfers;

use InvalidArgumentException;

final class OfficialTransferReportingEngine
{
    private const CERTIFIED_OFFICIAL_SOURCES = [
        'kap_official',
        'club_press_release',
        'federation_license',
    ];

    /**
     * Differentiate official transfer confirmation from market rumors.
     * Acceptance criterion: Unverified transfer never presented as confirmed.
     */
    public function evaluateTransfer(array $transferPayload): array
    {
        $sourceType = $transferPayload['source_type'] ?? 'unspecified_rumor';
        $headline = (string) ($transferPayload['headline'] ?? '');
        $body = (string) ($transferPayload['body'] ?? '');
        $isOfficial = in_array($sourceType, self::CERTIFIED_OFFICIAL_SOURCES, true);

        $fullText = mb_strtolower("{$headline} {$body}");

        // Phrases asserting confirmation
        $confirmationPhrases = [
            'resmen açıklandı',
            'transfer resmileşti',
            'kap bildirimi geldi',
            'imzayı attı',
            'kulüpten resmi açıklama',
            'resmi sözleşme imzalandı',
        ];

        $assertsConfirmation = false;
        foreach ($confirmationPhrases as $phrase) {
            if (str_contains($fullText, $phrase)) {
                $assertsConfirmation = true;
                break;
            }
        }

        // Strict rule: unverified rumors can NEVER assert confirmation
        if (! $isOfficial && $assertsConfirmation) {
            throw new InvalidArgumentException(
                "Violation: Unverified transfer source '{$sourceType}' cannot claim official confirmation ('{$phrase}')"
            );
        }

        if (! $isOfficial) {
            return [
                'classification' => 'market_rumor',
                'is_confirmed' => false,
                'can_publish' => false,
                'source_type' => $sourceType,
                'badge' => 'Söylenti / İddia',
                'reason' => 'Transfer is not confirmed by KAP or club official statement; automated publishing blocked.',
            ];
        }

        return [
            'classification' => 'confirmed_transfer',
            'is_confirmed' => true,
            'can_publish' => true,
            'source_type' => $sourceType,
            'badge' => $sourceType === 'kap_official' ? 'KAP Bildirimi' : 'Resmi Kulüp Açıklaması',
            'reason' => 'Legitimately certified official transfer announcement',
        ];
    }
}

