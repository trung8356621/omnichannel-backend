<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Support\PromptMarkdownParser;
use Illuminate\Support\Collection;

/**
 * Prompt SEO trên DB addon (`prompts`, connection `omi_seo_ai`).
 */
class SeoPrompt extends Prompt
{
    protected $casts = [
        'settings' => 'array',
        'variables' => 'json',
        'is_active' => 'boolean',
        'markdown_content' => 'string',
    ];

    /**
     * @return Collection<int, SeoPromptPart>
     */
    public function getVirtualPartsAttribute(): Collection
    {
        $partsData = PromptMarkdownParser::parse((string) ($this->markdown_content ?? ''));
        if ($partsData === []) {
            return collect();
        }

        return collect($partsData)
            ->map(static function (array $data): SeoPromptPart {
                $part = new SeoPromptPart();
                $part->forceFill($data);

                return $part;
            });
    }

    /**
     * Các block prompt parse từ markdown_content (không còn bảng prompt_parts).
     *
     * @return Collection<int, SeoPromptPart>
     */
    public function resolvedParts(): Collection
    {
        return $this->virtual_parts->values();
    }
}
