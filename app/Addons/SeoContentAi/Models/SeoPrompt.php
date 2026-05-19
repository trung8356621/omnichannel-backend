<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prompt SEO trên DB addon (`prompts`, connection `omi_seo_ai`).
 */
class SeoPrompt extends Prompt
{
    protected $casts = [
        'settings' => 'array',
        'variables' => 'json',
        'is_active' => 'boolean',
    ];

    public function parts(): HasMany
    {
        return $this->hasMany(SeoPromptPart::class, 'prompt_id', 'id');
    }
}
