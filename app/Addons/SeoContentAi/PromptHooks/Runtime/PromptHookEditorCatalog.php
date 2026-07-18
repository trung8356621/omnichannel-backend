<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Runtime;

use App\Addons\SeoContentAi\PromptHooks\Canonical\PromptHookDefinition;
use App\Addons\SeoContentAi\PromptHooks\Canonical\PromptHookStatus;

/**
 * UI catalog for Prompt editor dropdown — sourced from canonical RuntimeRegistry only.
 */
final class PromptHookEditorCatalog
{
    public function __construct(
        private readonly PromptHookRuntimeRegistry $registry,
    ) {}

    /**
     * Text-capable hooks for Prompt blocks (not image/video).
     *
     * @return list<array{
     *   hook_key: string,
     *   version: string,
     *   display_name: string,
     *   description: string,
     *   status: string,
     *   experimental: bool,
     *   output_type: string,
     *   input_summary: string,
     *   option_label: string
     * }>
     */
    public function optionsForTextPromptBlock(): array
    {
        $rows = [];
        foreach ($this->registry->list() as $definition) {
            if ($definition->status === PromptHookStatus::Disabled) {
                continue;
            }
            if (($definition->model->capability ?? 'text') !== 'text') {
                continue;
            }
            $rows[] = $this->toOption($definition);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

        return $rows;
    }

    /**
     * @return array<string, string> hook_key => option label
     */
    public function selectOptions(): array
    {
        $options = [];
        foreach ($this->optionsForTextPromptBlock() as $row) {
            $options[$row['hook_key']] = $row['option_label'];
        }

        return $options;
    }

    public function find(string $hookKey, string $version): PromptHookDefinition
    {
        return $this->registry->get($hookKey, $version);
    }

    public function latestPinnedOrFail(string $hookKey): PromptHookDefinition
    {
        $matches = array_values(array_filter(
            $this->registry->list(),
            static fn (PromptHookDefinition $d): bool => $d->key->value === $hookKey
                && $d->status !== PromptHookStatus::Disabled,
        ));
        if ($matches === []) {
            throw new \InvalidArgumentException("Hook [{$hookKey}] not found in editor catalog.");
        }

        // Prefer explicit 0.1.0 then highest semver string sort for this slice.
        usort(
            $matches,
            static fn (PromptHookDefinition $a, PromptHookDefinition $b): int => version_compare(
                $b->version->toString(),
                $a->version->toString(),
            ),
        );

        return $matches[0];
    }

    /**
     * @return array{
     *   hook_key: string,
     *   version: string,
     *   display_name: string,
     *   description: string,
     *   status: string,
     *   experimental: bool,
     *   output_type: string,
     *   input_summary: string,
     *   option_label: string
     * }
     */
    private function toOption(PromptHookDefinition $definition): array
    {
        $display = $this->displayName($definition);
        $experimental = $definition->status === PromptHookStatus::Experimental;
        $badge = $experimental
            ? ' ('.(string) __('seo-content-ai::prompt_hooks.experimental_badge').')'
            : '';

        return [
            'hook_key' => $definition->key->value,
            'version' => $definition->version->toString(),
            'display_name' => $display,
            'description' => $this->description($definition),
            'status' => $definition->status->value,
            'experimental' => $experimental,
            'output_type' => $definition->outputSchema->type,
            'input_summary' => $this->inputSummary($definition),
            'option_label' => $display.$badge,
        ];
    }

    private function displayName(PromptHookDefinition $definition): string
    {
        $langKey = 'seo-content-ai::prompt_hooks.'.$this->langSlug($definition->key->value).'.label';
        $translated = (string) __($langKey);
        if ($translated !== $langKey && $translated !== '') {
            return $translated;
        }

        return $definition->name !== '' ? $definition->name : $definition->key->value;
    }

    private function description(PromptHookDefinition $definition): string
    {
        $langKey = 'seo-content-ai::prompt_hooks.'.$this->langSlug($definition->key->value).'.description';
        $translated = (string) __($langKey);
        if ($translated !== $langKey && $translated !== '') {
            return $translated;
        }

        // Phase 1 dual-read may store label_key as name
        if (str_starts_with($definition->description, 'prompt_hooks.')) {
            return (string) __('seo-content-ai::'.$definition->description);
        }

        return $definition->description;
    }

    private function inputSummary(PromptHookDefinition $definition): string
    {
        $required = [];
        $optional = [];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            if (($schema['required'] ?? false) === true) {
                $required[] = (string) $field;
            } else {
                $optional[] = (string) $field;
            }
        }

        return 'required=['.implode(',', $required).'] optional=['.implode(',', $optional).']';
    }

    private function langSlug(string $hookKey): string
    {
        return str_replace('.', '_', $hookKey);
    }
}
