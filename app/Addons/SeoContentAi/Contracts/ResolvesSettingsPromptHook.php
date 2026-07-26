<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Contracts;

use App\Addons\SeoContentAi\Models\SeoPrompt;

interface ResolvesSettingsPromptHook
{
    public function resolveSettingsHook(string $hookKey): SeoPrompt;
}
