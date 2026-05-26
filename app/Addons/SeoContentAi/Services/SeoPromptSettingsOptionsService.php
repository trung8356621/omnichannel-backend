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
        return $this->activePromptOptionsForTool(null);
    }

    /**
     * @return array<int, string>
     */
    public function activeImagePromptOptions(): array
    {
        return $this->activePromptOptionsForTool('image');
    }

    /**
     * @return array<int, string>
     */
    public function activeVideoPromptOptions(): array
    {
        return $this->activePromptOptionsForTool('video');
    }

    /**
     * @return array<int, string>
     */
    private function activePromptOptionsForTool(?string $tool): array
    {
        $query = SeoPrompt::query()->where('is_active', true);

        if ($tool !== null) {
            $query->where('tools', $tool);
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
