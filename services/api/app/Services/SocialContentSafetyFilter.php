<?php

declare(strict_types=1);

namespace App\Services;

final class SocialContentSafetyFilter
{
    private const BLOCKED_PATTERNS = [
        '/\b(?:https?:\/\/|www\.|t\.me\/|wa\.me\/|bit\.ly\/)/iu' => 'external_link_or_spam',
        '/\b(?:kaçak\s*yayın|illegal\s*stream|iptv\s*link|canlı\s*bahis\s*sitesi|deneme\s*bonusu|yasa\s*dışı\s*bahis)\b/iu' => 'unlicensed_betting_or_stream_spam',
        '/\b(?:seni\s*öldürürüm|hepinizi\s*öldür|gebertirim|kill\s*yourself|stadyumu\s*yak)\b/iu' => 'violent_threat_or_incitement',
    ];

    /**
     * Sanitize and classify user-submitted match arena comment text.
     *
     * @return array{body: string, moderation_state: string, moderation_reason: ?string}
     */
    public function evaluate(string $rawBody): array
    {
        // Strip control chars and HTML tags while preserving UTF-8 Turkish/European characters.
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawBody) ?? '';
        $cleaned = trim(strip_tags($cleaned));
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;

        foreach (self::BLOCKED_PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $cleaned) === 1) {
                return [
                    'body' => $cleaned,
                    'moderation_state' => 'quarantined',
                    'moderation_reason' => $reason,
                ];
            }
        }

        return [
            'body' => $cleaned,
            'moderation_state' => 'visible',
            'moderation_reason' => null,
        ];
    }
}
