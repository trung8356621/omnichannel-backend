<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\PromptOwnership;

use App\Addons\SeoContentAi\Models\SeoPrompt;

/**
 * Canonical binding entry for Settings-owned hooks.
 * Workflow binds via Task flow_data.prompt_id (PromptUsageLocator).
 * Agent / Quick Chat reserved — not wired yet.
 */
final class PromptBindingResolver implements \App\Addons\SeoContentAi\Contracts\ResolvesSettingsPromptHook
{
    public function __construct(
        private readonly SettingsPromptBindingResolver $settingsResolver,
    ) {}

    public function resolveSettingsHook(string $hookKey): SeoPrompt
    {
        return $this->settingsResolver->resolve($hookKey);
    }

    public function resolveSettingsHookId(string $hookKey): ?int
    {
        return $this->settingsResolver->resolveId($hookKey);
    }

    public function isSettingsHookConfigured(string $hookKey): bool
    {
        return $this->settingsResolver->isConfigured($hookKey);
    }
}
