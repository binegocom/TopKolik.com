<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Publishing;

use App\Domain\Editorial\FactPackBuilder;
use App\Domain\Editorial\PublicationGuard;
use App\Domain\Editorial\Validation\DuplicateStorySuppressor;
use App\Domain\Editorial\Validation\HighRiskEditorialReviewGate;
use App\Domain\Editorial\Validation\NumericalIdentityClaimValidator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class AutonomousPublishingStateMachine
{
    public function __construct(
        private readonly PublicationGuard $publicationGuard = new PublicationGuard(),
        private readonly NumericalIdentityClaimValidator $numericalValidator = new NumericalIdentityClaimValidator(),
        private readonly HighRiskEditorialReviewGate $highRiskGate = new HighRiskEditorialReviewGate(),
        private readonly DuplicateStorySuppressor $duplicateSuppressor = new DuplicateStorySuppressor(),
        private readonly FactPackBuilder $factPackBuilder = new FactPackBuilder(),
    ) {}

    /**
     * Process draft through all verification gates and safely publish inside an atomic transaction.
     * Acceptance criterion: No partial publish, retry-safe concurrency tests.
     */
    public function publishArticle(
        array $draft,
        array $factPack,
        string $canonicalEventKey,
        string $idempotencyKey,
        bool $isCorrection = false
    ): array {
        // 1. Check idempotency: if already processed with this key, return safely
        $existingByIdempotency = DB::table('editorial_publication_records')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingByIdempotency !== null) {
            if ($existingByIdempotency->canonical_event_key !== $canonicalEventKey) {
                throw new InvalidArgumentException('Idempotency key belongs to a different canonical event');
            }

            return [
                'status' => $existingByIdempotency->publishing_state,
                'published' => $existingByIdempotency->publishing_state === 'published',
                'record_id' => $existingByIdempotency->id,
                'canonical_event_key' => $existingByIdempotency->canonical_event_key,
                'message' => 'Idempotent replay: already processed',
            ];
        }

        // A correction must reference an existing published canonical event.
        // Never allow a correction flag to create the first publication.
        if ($isCorrection && ! DB::table('editorial_publication_records')
            ->where('canonical_event_key', $canonicalEventKey)
            ->where('publishing_state', 'published')
            ->exists()) {
            throw new InvalidArgumentException('Correction requires an existing published canonical event');
        }

        // 2. Duplicate suppression evaluation
        $dupEval = $this->duplicateSuppressor->evaluateDuplicate(
            $canonicalEventKey,
            $draft['headline'],
            $isCorrection
        );

        if ($dupEval['is_duplicate']) {
            return [
                'status' => 'suppressed_duplicate',
                'published' => false,
                'record_id' => $dupEval['existing_id'],
                'canonical_event_key' => $canonicalEventKey,
                'message' => $dupEval['reason'],
            ];
        }

        // 3. Numerical & Identity claim validation
        $numVerdict = $this->numericalValidator->validate($draft, $factPack);
        if (! $numVerdict['valid']) {
            throw new InvalidArgumentException('Publishing rejected: ' . implode('; ', $numVerdict['reasons']));
        }

        // 4. PublicationGuard evaluation
        $candidate = $this->factPackBuilder->toPublicationCandidate(
            $factPack,
            $draft['headline'],
            $draft['body'],
            true
        );
        $decision = $this->publicationGuard->evaluate($candidate, new DateTimeImmutable());
        if (! $decision->allowed) {
            throw new RuntimeException('PublicationGuard rejected: ' . implode('; ', $decision->reasons));
        }

        // 5. High-Risk Review Gate
        $riskVerdict = $this->highRiskGate->evaluate($draft, $factPack);
        $publishingState = $riskVerdict['is_high_risk'] ? 'quarantined' : 'published';
        $quarantineReason = $riskVerdict['is_high_risk'] ? implode('; ', $riskVerdict['quarantine_reasons']) : null;

        // 6. Atomic Transactional Publish + Outbox Enqueue
        return DB::transaction(function () use (
            $draft,
            $factPack,
            $canonicalEventKey,
            $idempotencyKey,
            $publishingState,
            $quarantineReason,
            $dupEval
        ) {
            $recordId = (int) DB::table('editorial_publication_records')->insertGetId([
                'canonical_event_key' => $canonicalEventKey,
                'story_signal_id' => $draft['story_signal_id'],
                'fact_pack_id' => $draft['fact_pack_id'],
                'headline' => $draft['headline'],
                'summary' => $draft['summary'],
                'paragraphs' => json_encode($draft['paragraphs']),
                'cited_claim_ids' => json_encode($draft['cited_claim_ids']),
                'publishing_state' => $publishingState,
                'quarantine_reason' => $quarantineReason,
                'revision' => $dupEval['next_revision'],
                'idempotency_key' => $idempotencyKey,
                'published_at' => $publishingState === 'published' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Enqueue transactional outbox event only if successfully published
            if ($publishingState === 'published') {
                DB::table('editorial_outbox')->insert([
                    'publication_record_id' => $recordId,
                    'event_name' => 'article.published',
                    'payload' => json_encode([
                        'publication_id' => $recordId,
                        'canonical_event_key' => $canonicalEventKey,
                        'headline' => $draft['headline'],
                        'summary' => $draft['summary'],
                        'published_at' => now()->toIso8601String(),
                    ]),
                    'delivery_status' => 'pending',
                    'retry_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'status' => $publishingState,
                'published' => $publishingState === 'published',
                'record_id' => $recordId,
                'canonical_event_key' => $canonicalEventKey,
                'revision' => $dupEval['next_revision'],
                'quarantine_reason' => $quarantineReason,
                'message' => $publishingState === 'published' ? 'Article published and queued to outbox' : 'Article quarantined for manual staff review',
            ];
        });
    }
}
