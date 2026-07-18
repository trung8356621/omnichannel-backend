<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoPrompt;

final class SeoPromptSettingsOptionsService
{
    /**
     * @return array<int, string>
     */
    public function activePromptOptions(): array
    {
        return $this->activePromptOptionsForTools(null);
    }

    /**
     * @return array<int, string>
     */
    public function activeImagePromptOptions(): array
    {
        return $this->activePromptOptionsForTools(['image']);
    }

    /**
     * Image + Image Typography (editor general image source).
     *
     * @return array<int, string>
     */
    public function activeAnyImagePromptOptions(): array
    {
        return $this->activePromptOptionsForTools(['image', 'image_typography']);
    }

    /**
     * @return array<int, string>
     */
    public function activeTypographyImagePromptOptions(): array
    {
        return $this->activePromptOptionsForTools(['image_typography']);
    }

    /**
     * Prompt đang gắn đúng hook_key (Prompt Hook Phase 1).
     *
     * @return array<int, string>
     */
    public function activePromptOptionsForHook(string $hookKey): array
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return [];
        }

        return SeoPrompt::query()
            ->where('is_active', true)
            ->where('hook_key', $hookKey)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function activeVideoPromptOptions(): array
    {
        return $this->activePromptOptionsForTools(['video']);
    }

    /**
     * @param  list<string>|null  $tools
     * @return array<int, string>
     */
    private function activePromptOptionsForTools(?array $tools): array
    {
        $query = SeoPrompt::query()->where('is_active', true);

        if ($tools !== null && $tools !== []) {
            $query->whereIn('tools', $tools);
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
