<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks;

use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\PromptHooks\Data\PromptHookDefinition;
use App\Addons\SeoContentAi\Services\PromptRunnerService;

/**
 * Single place to assemble final prompt for hooks.
 *
 * Order:
 * 1. Base = PromptRunnerService::compilePrompt (markdown + {{variables}})
 * 2. Hook template from locale (before_prompt | after_prompt)
 *
 * Variables include:
 * - Each expose_to_prompt field
 * - Serialized [HOOK_INPUT]…[/HOOK_INPUT] as {{input}} (and hook_input)
 * - Resolved settings as {{setting_key}}
 */
final class PromptHookPromptAssembler
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly PromptHookTemplateRenderer $templateRenderer,
    ) {}

    /**
     * @param  array<string, mixed>  $exposedInput
     * @param  array<string, mixed>  $resolvedSettings
     * @return array{final_prompt: string, variables: array<string, string>}
     */
    public function assemble(
        PromptHookDefinition $definition,
        SeoPrompt $prompt,
        array $exposedInput,
        array $resolvedSettings,
    ): array {
        $variables = $this->buildVariables($exposedInput, $resolvedSettings);
        $base = $this->promptRunner->compilePrompt($prompt, $variables);
        $hookTemplate = $this->templateRenderer->render($definition, $exposedInput, $resolvedSettings);

        $final = $base;
        if ($hookTemplate !== null && trim($hookTemplate) !== '') {
            $final = $this->templateRenderer->position($definition) === 'before_prompt'
                ? trim($hookTemplate)."\n\n".trim($base)
                : trim($base)."\n\n".trim($hookTemplate);
        }

        return [
            'final_prompt' => trim($final),
            'variables' => $variables,
        ];
    }

    /**
     * @param  array<string, mixed>  $exposedInput
     * @param  array<string, mixed>  $resolvedSettings
     * @return array<string, string>
     */
    public function buildVariables(array $exposedInput, array $resolvedSettings): array
    {
        $variables = [];
        foreach ($exposedInput as $key => $value) {
            $variables[$key] = $this->stringify($value);
        }
        foreach ($resolvedSettings as $key => $value) {
            $variables[$key] = $this->stringify($value);
        }

        $inputBlock = $this->serializeHookInput($exposedInput);
        $variables['input'] = $inputBlock;
        $variables['hook_input'] = $inputBlock;

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $exposedInput
     */
    private function serializeHookInput(array $exposedInput): string
    {
        $lines = ['[HOOK_INPUT]'];
        foreach ($exposedInput as $key => $value) {
            $lines[] = $key.': '.$this->stringify($value);
        }
        $lines[] = '[/HOOK_INPUT]';

        return implode("\n", $lines);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
