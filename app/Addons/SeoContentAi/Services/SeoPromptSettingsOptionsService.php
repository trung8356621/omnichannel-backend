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
        return SeoPrompt::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
