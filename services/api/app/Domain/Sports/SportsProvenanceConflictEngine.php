<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SportsProvenanceConflictEngine
{
    /**
     * Record an incoming provider observation snapshot and evaluate conflict guards.
     * Conflicting versions trigger 'hold' to prevent incorrect public publication.
     */
    public function recordObservation(
        string $providerName,
        string $entityType,
        int $entityId,
        array $snapshot,
        int $revision = 1,
        ?DateTimeInterface $licenseExpiresAt = null
    ): array {
        // 1. License expiration check
        if ($licenseExpiresAt !== null && $licenseExpiresAt->getTimestamp() <= now()->getTimestamp()) {
            $recordId = $this->insertRecord(
                $providerName,
                $entityType,
                $entityId,
                $snapshot,
                $revision,
                'hold',
                'Provider data license has expired',
                $licenseExpiresAt
            );

            return [
                'record_id' => $recordId,
                'status' => 'hold',
                'can_publish' => false,
                'reason' => 'Provider data license has expired',
            ];
        }

        // 2. Fetch latest previous observation
        $latest = DB::table('sports_provenance_records')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('source_revision')
            ->first();

        if ($latest !== null) {
            $prevSnapshot = json_decode($latest->observation_snapshot, true) ?? [];

            // A. Monotonic revision conflict
            if ($revision < $latest->source_revision) {
                $recordId = $this->insertRecord(
                    $providerName,
                    $entityType,
                    $entityId,
                    $snapshot,
                    $revision,
                    'hold',
                    "Out-of-order revision conflict: incoming revision {$revision} is older than recorded {$latest->source_revision}",
                    $licenseExpiresAt
                );

                return [
                    'record_id' => $recordId,
                    'status' => 'hold',
                    'can_publish' => false,
                    'reason' => "Out-of-order revision conflict: incoming revision {$revision} is older than recorded {$latest->source_revision}",
                ];
            }

            // B. Status regression conflict (e.g. finished -> live)
            $prevStatus = $prevSnapshot['status'] ?? null;
            $newStatus = $snapshot['status'] ?? null;
            if ($prevStatus === 'finished' && $newStatus !== null && $newStatus !== 'finished') {
                $recordId = $this->insertRecord(
                    $providerName,
                    $entityType,
                    $entityId,
                    $snapshot,
                    $revision,
                    'hold',
                    "Illegal status regression: fixture already marked finished cannot revert to '{$newStatus}'",
                    $licenseExpiresAt
                );

                return [
                    'record_id' => $recordId,
                    'status' => 'hold',
                    'can_publish' => false,
                    'reason' => "Illegal status regression: fixture already marked finished cannot revert to '{$newStatus}'",
                ];
            }

            // C. Score regression conflict without VAR cancellation explanation
            $prevHomeScore = $prevSnapshot['home_score'] ?? null;
            $prevAwayScore = $prevSnapshot['away_score'] ?? null;
            $newHomeScore = $snapshot['home_score'] ?? null;
            $newAwayScore = $snapshot['away_score'] ?? null;
            $hasVarCancellation = ! empty($snapshot['is_var_cancelled']) || ! empty($snapshot['is_score_correction']);

            if (! $hasVarCancellation && (
                ($prevHomeScore !== null && $newHomeScore !== null && $newHomeScore < $prevHomeScore) ||
                ($prevAwayScore !== null && $newAwayScore !== null && $newAwayScore < $prevAwayScore)
            )) {
                $recordId = $this->insertRecord(
                    $providerName,
                    $entityType,
                    $entityId,
                    $snapshot,
                    $revision,
                    'hold',
                    'Score regression detected without VAR cancellation or authorized score correction',
                    $licenseExpiresAt
                );

                return [
                    'record_id' => $recordId,
                    'status' => 'hold',
                    'can_publish' => false,
                    'reason' => 'Score regression detected without VAR cancellation or authorized score correction',
                ];
            }
        }

        // 3. Clean observation: approved for publication
        $recordId = $this->insertRecord(
            $providerName,
            $entityType,
            $entityId,
            $snapshot,
            $revision,
            'clean',
            null,
            $licenseExpiresAt
        );

        return [
            'record_id' => $recordId,
            'status' => 'clean',
            'can_publish' => true,
            'reason' => null,
        ];
    }

    /**
     * Resolve a held conflict record manually or via editorial reconciliation.
     */
    public function resolveConflict(int $recordId, string $resolution = 'resolved', ?string $reason = null): bool
    {
        return DB::table('sports_provenance_records')
            ->where('id', $recordId)
            ->update([
                'conflict_status' => $resolution,
                'conflict_reason' => $reason,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * Get all records currently placed on hold.
     */
    public function getHeldRecords(?string $entityType = null): Collection
    {
        $query = DB::table('sports_provenance_records')->where('conflict_status', 'hold');
        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }

        return $query->orderByDesc('id')->get();
    }

    private function insertRecord(
        string $providerName,
        string $entityType,
        int $entityId,
        array $snapshot,
        int $revision,
        string $conflictStatus,
        ?string $conflictReason,
        ?DateTimeInterface $licenseExpiresAt
    ): int {
        return (int) DB::table('sports_provenance_records')->insertGetId([
            'provider_name' => $providerName,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'observation_snapshot' => json_encode($snapshot),
            'source_revision' => $revision,
            'conflict_status' => $conflictStatus,
            'conflict_reason' => $conflictReason,
            'license_expires_at' => $licenseExpiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

