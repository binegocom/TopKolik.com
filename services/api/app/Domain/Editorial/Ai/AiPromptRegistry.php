<?php

declare(strict_types=1);

namespace App\Domain\Editorial\Ai;

use InvalidArgumentException;

final class AiPromptRegistry
{
    private const REGISTRY = [
        'match_recap' => [
            'v1.0.0' => [
                'model' => 'gemini-2.5-flash',
                'cost_input_per_million' => 0.075,
                'cost_output_per_million' => 0.30,
                'system_prompt' => <<<'PROMPT'
You are the Topkolik Autonomous Sports Journalist.
Your task is to draft a high-quality, objective, fact-bound Turkish football match report based SOLELY on the provided evidence-bound fact pack.
RULES:
1. Strict factual discipline: NEVER hallucinate players, events, or statistics not in the fact pack.
2. Tone: Dynamic, authoritative, professional Turkish sports journalism.
3. Every sentence stating a fact must be grounded in an evidence claim.
4. Output MUST strictly adhere to the required JSON schema without markdown wrappers or code blocks.
PROMPT,
                'user_template' => <<<'TEMPLATE'
FACT PACK DATA:
<<<FACT_PACK>>>
{fact_pack_json}
<<<END_FACT_PACK>>>

Generate a JSON object matching the editorial schema with headline, summary, paragraphs, and cited_claim_ids.
TEMPLATE,
            ],
        ],
        'fixture_preview' => [
            'v1.0.0' => [
                'model' => 'gemini-2.5-flash',
                'cost_input_per_million' => 0.075,
                'cost_output_per_million' => 0.30,
                'system_prompt' => 'You are the Topkolik Match Analyst writing pre-game previews.',
                'user_template' => 'PREVIEW DATA: {preview_json}',
            ],
        ],
    ];

    /**
     * Retrieve prompt metadata and lineage.
     */
    public function getPromptDefinition(string $promptKey, string $version = 'v1.0.0'): array
    {
        if (! isset(self::REGISTRY[$promptKey][$version])) {
            throw new InvalidArgumentException("Unknown prompt '{$promptKey}' or version '{$version}' in registry.");
        }

        return array_merge(self::REGISTRY[$promptKey][$version], [
            'prompt_key' => $promptKey,
            'version' => $version,
        ]);
    }

    /**
     * Render a prompt with rigorous adversarial sanitization of dynamic fact parameters.
     */
    public function renderPrompt(string $promptKey, string $version, array $facts): array
    {
        $def = $this->getPromptDefinition($promptKey, $version);

        // Sanitize facts against prompt-injection delimiters and override attacks
        $sanitizedFactsJson = $this->sanitizeForPromptInjection(json_encode($facts));

        $userPrompt = str_replace('{fact_pack_json}', $sanitizedFactsJson, $def['user_template']);

        return [
            'prompt_key' => $promptKey,
            'version' => $version,
            'model' => $def['model'],
            'system_prompt' => $def['system_prompt'],
            'user_prompt' => $userPrompt,
            'cost_attribution' => [
                'model' => $def['model'],
                'input_cost_per_m' => $def['cost_input_per_million'],
                'output_cost_per_m' => $def['cost_output_per_million'],
            ],
        ];
    }

    /**
     * Adversarial prompt-injection defense.
     */
    public function sanitizeForPromptInjection(string $input): string
    {
        $neutralized = $input;

        // Strip or neutralize delimiter break attempts
        $delimiters = ['<<<FACT_PACK>>>', '<<<END_FACT_PACK>>>', '```json', '```'];
        foreach ($delimiters as $delim) {
            $neutralized = str_ireplace($delim, '', $neutralized);
        }

        // Neutralize role impersonation / instruction overrides
        $overrideAttempts = [
            '/system\s*:\s*/i' => '[filtered_system_role]: ',
            '/user\s*:\s*/i' => '[filtered_user_role]: ',
            '/assistant\s*:\s*/i' => '[filtered_assistant_role]: ',
            '/ignore previous instructions/i' => '[disallowed_instruction_override]',
            '/disregard all prior/i' => '[disallowed_instruction_override]',
            '/you are now/i' => '[disallowed_persona_switch]',
        ];

        foreach ($overrideAttempts as $pattern => $replacement) {
            $neutralized = (string) preg_replace($pattern, $replacement, $neutralized);
        }

        return $neutralized;
    }
}

