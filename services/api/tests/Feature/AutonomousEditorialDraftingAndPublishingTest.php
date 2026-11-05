<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Editorial\Ai\AiArticleDraftingPipeline;
use App\Domain\Editorial\FactPackBuilder;
use App\Domain\Editorial\Publishing\AutonomousPublishingStateMachine;
use App\Domain\Editorial\StorySignalDetector;
use App\Domain\Editorial\Validation\DuplicateStorySuppressor;
use App\Domain\Editorial\Validation\HighRiskEditorialReviewGate;
use App\Domain\Editorial\Validation\NumericalIdentityClaimValidator;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Sport;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AutonomousEditorialDraftingAndPublishingTest extends TestCase
{
    use RefreshDatabase;

    private AiArticleDraftingPipeline $draftingPipeline;
    private NumericalIdentityClaimValidator $claimValidator;
    private HighRiskEditorialReviewGate $highRiskGate;
    private DuplicateStorySuppressor $duplicateSuppressor;
    private AutonomousPublishingStateMachine $publishingStateMachine;
    private FactPackBuilder $factPackBuilder;
    private StorySignalDetector $signalDetector;

    private Fixture $fixture;
    private array $factPack;
    private int $storySignalId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->draftingPipeline = new AiArticleDraftingPipeline();
        $this->claimValidator = new NumericalIdentityClaimValidator();
        $this->highRiskGate = new HighRiskEditorialReviewGate();
        $this->duplicateSuppressor = new DuplicateStorySuppressor();
        $this->publishingStateMachine = new AutonomousPublishingStateMachine();
        $this->factPackBuilder = new FactPackBuilder();
        $this->signalDetector = new StorySignalDetector();

        $sport = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);
        $comp = Competition::create(['sport_id' => $sport->id, 'slug' => 'super-lig', 'name' => 'Trendyol Süper Lig']);
        $season = Season::create(['competition_id' => $comp->id, 'label' => '2026-2027', 'is_current' => true]);

        $home = Team::create(['sport_id' => $sport->id, 'slug' => 'galatasaray', 'name' => 'Galatasaray']);
        $away = Team::create(['sport_id' => $sport->id, 'slug' => 'fenerbahce', 'name' => 'Fenerbahçe']);

        $this->fixture = Fixture::create([
            'competition_id' => $comp->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->subHours(2),
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
            'source_revision' => 5,
        ]);

        $sig = $this->signalDetector->detectFinalMatchResult(
            $this->fixture,
            'tff_licensed_feed',
            ['status' => 'finished', 'home_score' => 2, 'away_score' => 1],
            5
        );
        $this->storySignalId = (int) $sig['signal_id'];

        $this->factPack = $this->factPackBuilder->buildMatchFactPack(
            storySignalId: $this->storySignalId,
            fixture: $this->fixture,
            licensedSourceRef: 'tff_licensed_feed:obs:101:rev5',
            licenseExpiresAt: now()->addMonths(6),
            factsPayload: [
                'source_snapshot_id' => 'snap_derby_101',
                'home_team_name' => 'Galatasaray',
                'away_team_name' => 'Fenerbahçe',
                'home_score' => 2,
                'away_score' => 1,
                'goals' => [
                    ['player' => 'Mauro Icardi', 'minute' => 24, 'team' => 'home'],
                    ['player' => 'Victor Osimhen', 'minute' => 88, 'team' => 'home'],
                    ['player' => 'Edin Džeko', 'minute' => 61, 'team' => 'away'],
                ],
            ]
        );
    }

    public function test_drafts_structured_article_and_rejects_unsupported_claims(): void
    {
        // 1. Clean drafting
        $draft = $this->draftingPipeline->draftFromFactPack($this->factPack);

        $this->assertNotEmpty($draft['headline']);
        $this->assertNotEmpty($draft['lead']);
        $this->assertNotEmpty($draft['body']);
        $this->assertCount(3, $draft['cited_claim_ids']);
        $this->assertEquals('draft', $draft['status']);

        // 2. Reject draft when generator outputs an unsupported claim ID
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Draft rejected: article cites unsupported or non-existent claim ID 'hallucinated_claim_999'");

        $this->draftingPipeline->draftFromFactPack(
            $this->factPack,
            generator: function () {
                return [
                    'headline' => 'Galatasaray 2 - 1 Fenerbahçe',
                    'summary' => 'Maç özeti',
                    'paragraphs' => ['Galatasaray derbiyi kazandı.'],
                    'cited_claim_ids' => ['hallucinated_claim_999'],
                ];
            }
        );
    }

    public function test_validates_exact_numerical_and_identity_claims(): void
    {
        $validDraft = [
            'headline' => 'Galatasaray 2 - 1 Fenerbahçe: Derbide Zafer',
            'summary' => 'Galatasaray derbiyi 2-1 kazandı.',
            'body' => 'Mauro Icardi 24. dakikada ve Victor Osimhen 88. dakikada fileleri sarstı. Džeko 61. dakikada karşılık verdi.',
            'cited_claim_ids' => ['claim_score_' . $this->fixture->id, 'claim_goal_' . $this->fixture->id . '_0'],
        ];

        $verdict = $this->claimValidator->validate($validDraft, $this->factPack);
        $this->assertTrue($verdict['valid']);
        $this->assertEmpty($verdict['reasons']);

        // Assert wrong score (e.g. claiming 3-1 instead of 2-1)
        $wrongScoreDraft = array_merge($validDraft, [
            'headline' => 'Galatasaray 3 - 1 Fenerbahçe: Farklı Galibiyet',
        ]);
        $wrongScoreVerdict = $this->claimValidator->validate($wrongScoreDraft, $this->factPack);
        $this->assertFalse($wrongScoreVerdict['valid']);
        $this->assertStringContainsString('Wrong score claim', $wrongScoreVerdict['reasons'][0]);

        // Assert wrong minute (e.g. claiming goal was in minute 77)
        $wrongMinuteDraft = array_merge($validDraft, [
            'body' => 'Icardi 77. dakikada golü attı.',
        ]);
        $wrongMinuteVerdict = $this->claimValidator->validate($wrongMinuteDraft, $this->factPack);
        $this->assertFalse($wrongMinuteVerdict['valid']);
        $this->assertStringContainsString('Unverified minute claim: minute 77', $wrongMinuteVerdict['reasons'][0]);
    }

    public function test_high_risk_review_gate_quarantines_rumors_and_unverified_claims(): void
    {
        // 1. Clean report passes
        $cleanDraft = [
            'headline' => 'Galatasaray 2 - 1 Fenerbahçe',
            'summary' => 'Maç sona erdi.',
            'body' => 'Icardi ve Osimhen golleri attı.',
        ];
        $cleanVerdict = $this->highRiskGate->evaluate($cleanDraft, $this->factPack);
        $this->assertFalse($cleanVerdict['is_high_risk']);
        $this->assertTrue($cleanVerdict['can_auto_publish']);

        // 2. Rumor detection
        $rumorDraft = [
            'headline' => 'Transfer İddiası Ortalığı Karıştırdı',
            'summary' => 'Yıldız oyuncunun ayrılacağı iddia edildi.',
            'body' => 'Kulislerde konuşulan bilgilere göre transfer bitmek üzere.',
        ];
        $rumorVerdict = $this->highRiskGate->evaluate($rumorDraft, $this->factPack);
        $this->assertTrue($rumorVerdict['is_high_risk']);
        $this->assertFalse($rumorVerdict['can_auto_publish']);
        $this->assertStringContainsString('RUMOR_DETECTED', $rumorVerdict['quarantine_reasons'][0]);

        // 3. Scandal / match-fixing allegation
        $scandalDraft = [
            'headline' => 'Derbide Hakem Skandalı',
            'summary' => 'Maçta şike iddiaları gündemde.',
            'body' => 'Hakem kararları tepki çekti.',
        ];
        $scandalVerdict = $this->highRiskGate->evaluate($scandalDraft, $this->factPack);
        $this->assertTrue($scandalVerdict['is_high_risk']);
        $this->assertFalse($scandalVerdict['can_auto_publish']);
        $this->assertStringContainsString('SCANDAL_ALLEGATION_RISK', $scandalVerdict['quarantine_reasons'][0]);
    }

    public function test_duplicate_suppressor_produces_one_canonical_article_and_allows_corrections(): void
    {
        $canonicalKey = $this->duplicateSuppressor->generateCanonicalEventKey(
            'futbol',
            'super-lig',
            $this->fixture->id,
            'final_match_result'
        );

        // First publication
        $firstEval = $this->duplicateSuppressor->evaluateDuplicate($canonicalKey, 'Derbide Zafer');
        $this->assertFalse($firstEval['is_duplicate']);
        $this->assertEquals('create_canonical', $firstEval['action']);
        $this->assertEquals(1, $firstEval['next_revision']);

        // Fake DB record to simulate parallel ingest
        DB::table('editorial_publication_records')->insert([
            'canonical_event_key' => $canonicalKey,
            'story_signal_id' => $this->storySignalId,
            'fact_pack_id' => $this->factPack['fact_pack_id'],
            'headline' => 'Derbide Zafer',
            'summary' => 'Özet',
            'paragraphs' => json_encode(['P1']),
            'cited_claim_ids' => json_encode(['c1']),
            'publishing_state' => 'published',
            'revision' => 1,
            'idempotency_key' => 'idem_worker_01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second parallel ingest attempt for same event -> Suppressed
        $secondEval = $this->duplicateSuppressor->evaluateDuplicate($canonicalKey, 'Derbide Zafer (Parallel)');
        $this->assertTrue($secondEval['is_duplicate']);
        $this->assertEquals('suppress_duplicate', $secondEval['action']);

        // Legitimate VAR/Score Correction -> Allowed revision 2
        $correctionEval = $this->duplicateSuppressor->evaluateDuplicate($canonicalKey, 'DÜZELTME: Derbi Skoru', isCorrection: true);
        $this->assertFalse($correctionEval['is_duplicate']);
        $this->assertEquals('publish_revision', $correctionEval['action']);
        $this->assertEquals(2, $correctionEval['next_revision']);
    }

    public function test_autonomous_publishing_state_machine_executes_transactional_publish_and_outbox(): void
    {
        $canonicalKey = 'canonical:futbol:super-lig:' . $this->fixture->id . ':final_match_result';
        $idempotencyKey = 'idem_publish_test_' . uniqid();

        $validDraft = [
            'headline' => 'Galatasaray 2 - 1 Fenerbahçe',
            'summary' => 'Galatasaray derbiyi 2-1 kazandı.',
            'lead' => 'Galatasaray derbiyi 2-1 kazandı.',
            'body' => 'Icardi 24. dakikada penaltıdan golü attı. Osimhen 88. dakikada galibiyeti getirdi.',
            'paragraphs' => [
                'Galatasaray derbiyi 2-1 kazandı.',
                'Icardi 24. dakikada penaltıdan golü attı. Osimhen 88. dakikada galibiyeti getirdi.',
            ],
            'cited_claim_ids' => ['claim_score_' . $this->fixture->id, 'claim_goal_' . $this->fixture->id . '_0'],
            'story_signal_id' => $this->storySignalId,
            'fact_pack_id' => $this->factPack['fact_pack_id'],
        ];

        // 1. Execute safe publish
        $pubResult = $this->publishingStateMachine->publishArticle(
            $validDraft,
            $this->factPack,
            $canonicalKey,
            $idempotencyKey
        );

        $this->assertTrue($pubResult['published']);
        $this->assertEquals('published', $pubResult['status']);
        $this->assertNotNull($pubResult['record_id']);

        // Verify outbox entry created atomically
        $outboxItem = DB::table('editorial_outbox')
            ->where('publication_record_id', $pubResult['record_id'])
            ->first();
        $this->assertNotNull($outboxItem);
        $this->assertEquals('article.published', $outboxItem->event_name);
        $this->assertEquals('pending', $outboxItem->delivery_status);

        // 2. Retry with same idempotency key -> Idempotent replay safe
        $replayResult = $this->publishingStateMachine->publishArticle(
            $validDraft,
            $this->factPack,
            $canonicalKey,
            $idempotencyKey
        );
        $this->assertEquals($pubResult['record_id'], $replayResult['record_id']);
        $this->assertStringContainsString('Idempotent replay', $replayResult['message']);
    }

    public function test_idempotency_key_cannot_replay_a_different_canonical_event(): void
    {
        $draft = $this->draftingPipeline->draftFromFactPack($this->factPack);
        $key = 'idem_event_binding_' . uniqid();
        $canonical = 'canonical:futbol:super-lig:' . $this->fixture->id . ':final_match_result';

        $first = $this->publishingStateMachine->publishArticle(
            $draft,
            $this->factPack,
            $canonical,
            $key
        );
        $this->assertSame('published', $first['status']);

        try {
            $this->publishingStateMachine->publishArticle(
                $draft,
                $this->factPack,
                $canonical . ':unrelated_event',
                $key
            );
            $this->fail('An idempotency key must not be reusable across canonical events.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Idempotency key belongs to a different canonical event',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, DB::table('editorial_publication_records')
            ->where('idempotency_key', $key)->count());
        $this->assertSame(1, DB::table('editorial_outbox')
            ->where('publication_record_id', $first['record_id'])->count());
    }

    public function test_correction_requires_an_existing_published_canonical_event(): void
    {
        $draft = $this->draftingPipeline->draftFromFactPack($this->factPack);
        $canonical = 'canonical:futbol:super-lig:' . $this->fixture->id . ':correction_without_source';

        try {
            $this->publishingStateMachine->publishArticle(
                $draft,
                $this->factPack,
                $canonical,
                'idem_missing_source_' . uniqid(),
                isCorrection: true
            );
            $this->fail('A correction cannot create the initial canonical publication.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Correction requires an existing published canonical event',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, DB::table('editorial_publication_records')
            ->where('canonical_event_key', $canonical)->count());
        $this->assertSame(0, DB::table('editorial_outbox')->count());
    }

    public function test_correction_does_not_use_a_quarantined_record_as_published_source(): void
    {
        $draft = $this->draftingPipeline->draftFromFactPack($this->factPack);
        $canonical = 'canonical:futbol:super-lig:' . $this->fixture->id . ':quarantined_source';

        DB::table('editorial_publication_records')->insert([
            'canonical_event_key' => $canonical,
            'story_signal_id' => $this->storySignalId,
            'fact_pack_id' => $this->factPack['fact_pack_id'],
            'headline' => $draft['headline'],
            'summary' => $draft['summary'],
            'paragraphs' => json_encode($draft['paragraphs']),
            'cited_claim_ids' => json_encode($draft['cited_claim_ids']),
            'publishing_state' => 'quarantined',
            'revision' => 1,
            'idempotency_key' => 'idem_quarantined_source_' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Correction requires an existing published canonical event');

        try {
            $this->publishingStateMachine->publishArticle(
                $draft,
                $this->factPack,
                $canonical,
                'idem_correction_quarantined_' . uniqid(),
                isCorrection: true
            );
        } finally {
            $this->assertSame(1, DB::table('editorial_publication_records')
                ->where('canonical_event_key', $canonical)->count());
            $this->assertSame(0, DB::table('editorial_outbox')->count());
        }
    }

    public function test_autonomous_publishing_state_machine_quarantines_high_risk_story(): void
    {
        $canonicalKey = 'canonical:futbol:super-lig:' . $this->fixture->id . ':quarantine_test';
        $idempotencyKey = 'idem_quarantine_' . uniqid();

        $riskyDraft = [
            'headline' => 'Galatasaray 2 - 1 Fenerbahçe',
            'summary' => 'Maç sonrası şike ve hakem skandalı iddiaları ortalığı karıştırdı.',
            'lead' => 'Derbi sonrası skandal iddia edildi.',
            'body' => 'Hakem skandalı nedeniyle maç sonrası ortalık karıştı.',
            'paragraphs' => ['Derbi sonrası skandal iddia edildi.'],
            'cited_claim_ids' => ['claim_score_' . $this->fixture->id],
            'story_signal_id' => $this->storySignalId,
            'fact_pack_id' => $this->factPack['fact_pack_id'],
        ];

        $pubResult = $this->publishingStateMachine->publishArticle(
            $riskyDraft,
            $this->factPack,
            $canonicalKey,
            $idempotencyKey
        );

        $this->assertFalse($pubResult['published']);
        $this->assertEquals('quarantined', $pubResult['status']);
        $this->assertNotNull($pubResult['quarantine_reason']);

        // Verify NO outbox message created for quarantined story
        $outboxItem = DB::table('editorial_outbox')
            ->where('publication_record_id', $pubResult['record_id'])
            ->first();
        $this->assertNull($outboxItem);
    }
}
