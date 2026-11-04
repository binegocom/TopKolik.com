<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Sports\SportsProvenanceConflictEngine;
use App\Models\Fixture;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StorySignalDetector
{
    public function __construct(
        private readonly ?SportsProvenanceConflictEngine $conflictEngine = null,
    ) {}

    /**
     * Evaluate deterministic story signal for final match result.
     * Acceptance criterion: No news emitted for missing/conflicted/provisional facts.
     */
    public function detectFinalMatchResult(
        Fixture $fixture,
        string $providerName,
        array $observationSnapshot,
        int $revision = 1,
        ?int $provenanceRecordId = null
    ): array {
        // 1. Provisional check: fixture must be finalized
        if ($fixture->status !== 'finished') {
            return [
                'emitted' => false,
                'signal_id' => null,
                'reason' => "Provisional fixture state '{$fixture->status}': news signal emission suppressed until match is finished.",
            ];
        }

        // 2. Missing facts check: scores must be recorded
        if ($fixture->home_score === null || $fixture->away_score === null) {
            return [
                'emitted' => false,
                'signal_id' => null,
                'reason' => 'Missing final score: cannot emit match result story without verified scores.',
            ];
        }

        // 3. Conflicted observation check
        if (! empty($observationSnapshot['has_conflict']) || ! empty($observationSnapshot['conflict_status']) && $observationSnapshot['conflict_status'] === 'hold') {
            return [
                'emitted' => false,
                'signal_id' => null,
                'reason' => 'Conflicted source observation: story signal emission suppressed.',
            ];
        }

        if ($this->conflictEngine !== null) {
            $held = $this->conflictEngine->getHeldRecords('fixture');
            if ($held->contains('entity_id', $fixture->id)) {
                return [
                    'emitted' => false,
                    'signal_id' => null,
                    'reason' => "Fixture #{$fixture->id} is held in provenance conflict: story signal emission blocked.",
                ];
            }
        }

        // 4. Clean, deterministic story signal emission
        $payload = [
            'fixture_id' => $fixture->id,
            'home_team_id' => $fixture->home_team_id,
            'away_team_id' => $fixture->away_team_id,
            'home_score' => $fixture->home_score,
            'away_score' => $fixture->away_score,
            'revision' => $revision,
            'starts_at' => $fixture->starts_at?->toIso8601String(),
            'events' => $observationSnapshot['events'] ?? [],
        ];

        $signalId = (int) DB::table('editorial_story_signals')->insertGetId([
            'signal_type' => 'final_match_result',
            'entity_type' => 'fixture',
            'entity_id' => $fixture->id,
            'provider_name' => $providerName,
            'provenance_record_id' => $provenanceRecordId,
            'status' => 'emitted',
            'payload' => json_encode($payload),
            'emitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'emitted' => true,
            'signal_id' => $signalId,
            'signal_type' => 'final_match_result',
            'reason' => null,
            'payload' => $payload,
        ];
    }

    /**
     * Evaluate deterministic story signal for fixture status change (e.g. postponed or rescheduled).
     */
    public function detectFixtureChange(
        Fixture $fixture,
        string $changeType,
        string $providerName,
        array $changeDetails = []
    ): array {
        if (! in_array($fixture->status, ['postponed', 'cancelled', 'rescheduled'], true) && $changeType !== 'rescheduled') {
            return [
                'emitted' => false,
                'signal_id' => null,
                'reason' => "Fixture is in normal state '{$fixture->status}', no fixture change signal required.",
            ];
        }

        $payload = array_merge([
            'fixture_id' => $fixture->id,
            'status' => $fixture->status,
            'change_type' => $changeType,
        ], $changeDetails);

        $signalId = (int) DB::table('editorial_story_signals')->insertGetId([
            'signal_type' => 'fixture_change',
            'entity_type' => 'fixture',
            'entity_id' => $fixture->id,
            'provider_name' => $providerName,
            'status' => 'emitted',
            'payload' => json_encode($payload),
            'emitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'emitted' => true,
            'signal_id' => $signalId,
            'signal_type' => 'fixture_change',
            'payload' => $payload,
        ];
    }

    /**
     * Evaluate deterministic official club or federation announcement.
     */
    public function detectOfficialAnnouncement(
        string $providerName,
        string $entityType,
        int $entityId,
        array $announcementPayload
    ): array {
        if (empty($announcementPayload['headline']) || empty($announcementPayload['body'])) {
            throw new InvalidArgumentException('Official announcement requires non-empty headline and body.');
        }

        $signalId = (int) DB::table('editorial_story_signals')->insertGetId([
            'signal_type' => 'official_statement',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'provider_name' => $providerName,
            'status' => 'emitted',
            'payload' => json_encode($announcementPayload),
            'emitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'emitted' => true,
            'signal_id' => $signalId,
            'signal_type' => 'official_statement',
            'payload' => $announcementPayload,
        ];
    }
}

