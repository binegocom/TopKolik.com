<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Validation;

use Illuminate\Support\Facades\DB;

final class DuplicateStorySuppressor
{
    /**
     * Compute deterministic canonical key for a sports event story.
     */
    public function generateCanonicalEventKey(
        string $sportSlug,
        string $competitionSlug,
        int|string $entityId,
        string $eventType
    ): string {
        return "canonical:{$sportSlug}:{$competitionSlug}:{$entityId}:{$eventType}";
    }

    /**
     * Check for duplicate stories on the same event.
     * Parallel ingest must produce only one canonical article; corrections supersede cleanly.
     * Acceptance criterion: Parallel ingest produces one canonical article.
     */
    public function evaluateDuplicate(
        string $canonicalEventKey,
        string $headline,
        bool $isCorrection = false
    ): array {
        $existing = DB::table('editorial_publication_records')
            ->where('canonical_event_key', $canonicalEventKey)
            ->orderByDesc('revision')
            ->first();

        if ($existing !== null) {
            // Valid correction: allows new revision without duplicate suppression
            if ($isCorrection) {
                return [
                    'is_duplicate' => false,
                    'is_correction' => true,
                    'action' => 'publish_revision',
                    'existing_id' => $existing->id,
                    'next_revision' => ((int) $existing->revision) + 1,
                    'reason' => 'Authorized score/VAR correction superseding previous revision',
                ];
            }

            // Normal duplicate: suppress parallel worker publication
            return [
                'is_duplicate' => true,
                'is_correction' => false,
                'action' => 'suppress_duplicate',
                'existing_id' => $existing->id,
                'next_revision' => (int) $existing->revision,
                'reason' => "Duplicate story detected for canonical key '{$canonicalEventKey}' (existing record #{$existing->id})",
            ];
        }

        // Fresh canonical article
        return [
            'is_duplicate' => false,
            'is_correction' => false,
            'action' => 'create_canonical',
            'existing_id' => null,
            'next_revision' => 1,
            'reason' => 'Unique canonical event article',
        ];
    }
}
