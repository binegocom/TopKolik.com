<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Ai;

use InvalidArgumentException;

final class AiArticleDraftingPipeline
{
    public function __construct(
        private readonly AiPromptRegistry $promptRegistry = new AiPromptRegistry(),
        private readonly AiArticleSchema $articleSchema = new AiArticleSchema(),
    ) {}

    /**
     * Draft a structured sports article from an evidence-bound fact pack.
     * Acceptance criterion: Claims are structured and no unsupported values pass.
     */
    public function draftFromFactPack(
        array $factPack,
        string $promptKey = 'match_recap',
        string $promptVersion = 'v1.0.0',
        ?callable $generator = null
    ): array {
        // 1. Render prompt with strict delimiter and injection protection
        $renderedPrompt = $this->promptRegistry->renderPrompt(
            $promptKey,
            $promptVersion,
            $factPack['metric_facts'] ?? []
        );

        // 2. Generate raw model output
        $rawOutput = $generator !== null
            ? $generator($renderedPrompt['system_prompt'], $renderedPrompt['user_prompt'])
            : $this->generateDeterministicFallback($factPack);

        // 3. Validate against rigid editorial schema
        $validatedArticle = $this->articleSchema->validateOutput($rawOutput);

        // 4. Strict evidence claim verification: ensure all cited claims exist in fact pack
        $factPackClaims = collect($factPack['evidence_claims'] ?? [])
            ->pluck('claim_id')
            ->filter()
            ->all();

        foreach ($validatedArticle['cited_claim_ids'] as $citedClaimId) {
            if (! in_array($citedClaimId, $factPackClaims, true)) {
                throw new InvalidArgumentException("Draft rejected: article cites unsupported or non-existent claim ID '{$citedClaimId}'");
            }
        }

        // 5. Structure finalized article draft
        $paragraphs = $validatedArticle['paragraphs'];
        $lead = $paragraphs[0] ?? '';
        $body = implode("\n\n", $paragraphs);

        return [
            'headline' => $validatedArticle['headline'],
            'lead' => $lead,
            'body' => $body,
            'summary' => $validatedArticle['summary'],
            'paragraphs' => $paragraphs,
            'cited_claim_ids' => $validatedArticle['cited_claim_ids'],
            'fact_pack_id' => $factPack['fact_pack_id'] ?? null,
            'story_signal_id' => $factPack['story_signal_id'] ?? null,
            'model_lineage' => [
                'model' => $renderedPrompt['model'],
                'prompt_key' => $promptKey,
                'prompt_version' => $promptVersion,
                'cost_attribution' => $renderedPrompt['cost_attribution'],
            ],
            'schema_version' => $validatedArticle['schema_version'],
            'status' => 'draft',
        ];
    }

    private function generateDeterministicFallback(array $factPack): array
    {
        $metrics = $factPack['metric_facts'] ?? [];
        $homeName = $metrics['home_team']['name'] ?? 'Ev Sahibi';
        $awayName = $metrics['away_team']['name'] ?? 'Deplasman';
        $homeScore = $metrics['home_team']['score'] ?? 0;
        $awayScore = $metrics['away_team']['score'] ?? 0;

        $claims = collect($factPack['evidence_claims'] ?? [])->pluck('claim_id')->take(3)->values()->all();
        if (empty($claims)) {
            $claims = ['claim_score_default'];
        }

        return [
            'headline' => "{$homeName} {$homeScore} - {$awayScore} {$awayName}",
            'summary' => "Trendyol Süper Lig karşılaşmasında {$homeName}, {$awayName} karşısında maçı {$homeScore}-{$awayScore} tamamladı.",
            'paragraphs' => [
                "Karşılaşma {$homeScore}-{$awayScore} skorla sona erdi.",
                'Mücadele boyunca takımlar yüksek tempo sergiledi.',
            ],
            'cited_claim_ids' => $claims,
        ];
    }
}
