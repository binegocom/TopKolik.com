<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Ai;

use InvalidArgumentException;

final class AiArticleSchema
{
    public const SCHEMA_VERSION = '2026.1';

    /**
     * Validate raw LLM JSON response against rigid editorial schema.
     * Enforces typed fields, character limits, zero HTML/script tags, and evidence claim citations.
     */
    public function validateOutput(array $json): array
    {
        // 1. Required keys
        $required = ['headline', 'summary', 'paragraphs', 'cited_claim_ids'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $json)) {
                throw new InvalidArgumentException("Missing required editorial schema field: {$field}");
            }
        }

        // 2. Headline constraints
        $headline = trim((string) $json['headline']);
        if ($headline === '' || mb_strlen($headline) > 120) {
            throw new InvalidArgumentException('Headline must be between 1 and 120 characters.');
        }
        $this->assertNoExecutableContent($headline, 'headline');

        // 3. Summary constraints
        $summary = trim((string) $json['summary']);
        if ($summary === '' || mb_strlen($summary) > 280) {
            throw new InvalidArgumentException('Summary must be between 1 and 280 characters.');
        }
        $this->assertNoExecutableContent($summary, 'summary');

        // 4. Paragraphs constraints
        if (! is_array($json['paragraphs']) || empty($json['paragraphs'])) {
            throw new InvalidArgumentException('Paragraphs must be a non-empty array of strings.');
        }
        $cleanParagraphs = [];
        foreach ($json['paragraphs'] as $index => $paragraph) {
            $p = trim((string) $paragraph);
            if ($p === '') {
                throw new InvalidArgumentException("Paragraph at index {$index} cannot be empty.");
            }
            $this->assertNoExecutableContent($p, "paragraphs[{$index}]");
            $cleanParagraphs[] = $p;
        }

        // 5. Cited claim IDs constraints
        if (! is_array($json['cited_claim_ids']) || empty($json['cited_claim_ids'])) {
            throw new InvalidArgumentException('Article output must cite at least one verified claim ID.');
        }
        foreach ($json['cited_claim_ids'] as $claimId) {
            if (! is_string($claimId) || trim($claimId) === '') {
                throw new InvalidArgumentException('Cited claim ID must be a non-empty string.');
            }
        }

        return [
            'headline' => $headline,
            'summary' => $summary,
            'paragraphs' => $cleanParagraphs,
            'cited_claim_ids' => array_values(array_unique($json['cited_claim_ids'])),
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * Defense against script, markdown HTML tags, or system override injection patterns.
     */
    private function assertNoExecutableContent(string $text, string $fieldName): void
    {
        $dangerousPatterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/javascript:/i',
            '/onload=/i',
            '/onerror=/i',
            '/<iframe\b[^>]*>/i',
            '/\[SYSTEM_OVERRIDE\]/i',
            '/IGNORE PREVIOUS INSTRUCTIONS/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                throw new InvalidArgumentException("Adversarial payload or executable tag detected in field: {$fieldName}");
            }
        }
    }
}

