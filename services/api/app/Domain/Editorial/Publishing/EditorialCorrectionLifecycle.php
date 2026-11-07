<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Publishing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EditorialCorrectionLifecycle
{
    /**
     * Apply a verified VAR or result correction to an exact published article.
     * Acceptance criterion: VAR/result corrections update exact article safely.
     */
    public function applyCorrection(
        int $publicationRecordId,
        array $updatedDraft,
        string $correctionType,
        string $reason
    ): array {
        return DB::transaction(function () use (
            $publicationRecordId,
            $updatedDraft,
            $correctionType,
            $reason
        ) {
            $record = DB::table('editorial_publication_records')
                ->where('id', $publicationRecordId)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new InvalidArgumentException("Publication record #{$publicationRecordId} not found");
            }

            $prevRevision = (int) $record->revision;
            $newRevision = $prevRevision + 1;

            $prevSnapshot = [
                'headline' => $record->headline,
                'summary' => $record->summary,
                'paragraphs' => json_decode($record->paragraphs, true),
                'revision' => $prevRevision,
            ];

            $newSnapshot = [
                'headline' => $updatedDraft['headline'],
                'summary' => $updatedDraft['summary'],
                'paragraphs' => $updatedDraft['paragraphs'],
                'revision' => $newRevision,
            ];

            // 1. Record correction history entry
            DB::table('editorial_corrections_history')->insert([
                'publication_record_id' => $publicationRecordId,
                'previous_revision' => $prevRevision,
                'new_revision' => $newRevision,
                'correction_type' => $correctionType,
                'reason' => $reason,
                'previous_snapshot' => json_encode($prevSnapshot),
                'new_snapshot' => json_encode($newSnapshot),
                'created_at' => now(),
            ]);

            // 2. Update canonical publication record
            DB::table('editorial_publication_records')
                ->where('id', $publicationRecordId)
                ->update([
                    'headline' => $updatedDraft['headline'],
                    'summary' => $updatedDraft['summary'],
                    'paragraphs' => json_encode($updatedDraft['paragraphs']),
                    'cited_claim_ids' => json_encode($updatedDraft['cited_claim_ids'] ?? json_decode($record->cited_claim_ids, true)),
                    'revision' => $newRevision,
                    'publishing_state' => 'published',
                    'updated_at' => now(),
                ]);

            // 3. Transactional Outbox for CDN Purge and Syndication Push
            DB::table('editorial_outbox')->insert([
                'publication_record_id' => $publicationRecordId,
                'event_name' => 'article.corrected',
                'payload' => json_encode([
                    'publication_id' => $publicationRecordId,
                    'previous_revision' => $prevRevision,
                    'new_revision' => $newRevision,
                    'correction_type' => $correctionType,
                    'reason' => $reason,
                    'headline' => $updatedDraft['headline'],
                    'corrected_at' => now()->toIso8601String(),
                ]),
                'delivery_status' => 'pending',
                'retry_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'corrected' => true,
                'publication_record_id' => $publicationRecordId,
                'previous_revision' => $prevRevision,
                'new_revision' => $newRevision,
                'correction_type' => $correctionType,
                'reason' => $reason,
            ];
        });
    }

    /**
     * Retract an entire article with full audit record.
     */
    public function retractArticle(int $publicationRecordId, string $reason): array
    {
        return DB::transaction(function () use ($publicationRecordId, $reason) {
            $record = DB::table('editorial_publication_records')
                ->where('id', $publicationRecordId)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new InvalidArgumentException("Publication record #{$publicationRecordId} not found");
            }

            $prevRevision = (int) $record->revision;
            $newRevision = $prevRevision + 1;

            $prevSnapshot = [
                'headline' => $record->headline,
                'summary' => $record->summary,
                'state' => $record->publishing_state,
            ];

            DB::table('editorial_corrections_history')->insert([
                'publication_record_id' => $publicationRecordId,
                'previous_revision' => $prevRevision,
                'new_revision' => $newRevision,
                'correction_type' => 'full_retraction',
                'reason' => $reason,
                'previous_snapshot' => json_encode($prevSnapshot),
                'new_snapshot' => json_encode(['state' => 'retracted', 'reason' => $reason]),
                'created_at' => now(),
            ]);

            DB::table('editorial_publication_records')
                ->where('id', $publicationRecordId)
                ->update([
                    'publishing_state' => 'retracted',
                    'quarantine_reason' => $reason,
                    'revision' => $newRevision,
                    'updated_at' => now(),
                ]);

            DB::table('editorial_outbox')->insert([
                'publication_record_id' => $publicationRecordId,
                'event_name' => 'article.retracted',
                'payload' => json_encode([
                    'publication_id' => $publicationRecordId,
                    'retracted_at' => now()->toIso8601String(),
                    'reason' => $reason,
                ]),
                'delivery_status' => 'pending',
                'retry_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'retracted' => true,
                'publication_record_id' => $publicationRecordId,
                'status' => 'retracted',
                'reason' => $reason,
            ];
        });
    }

    public function getCorrectionHistory(int $publicationRecordId): array
    {
        return DB::table('editorial_corrections_history')
            ->where('publication_record_id', $publicationRecordId)
            ->orderBy('new_revision')
            ->get()
            ->toArray();
    }
}

