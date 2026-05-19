<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\SeoArticle;

final class TaskTestContext
{
    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public readonly ?SeoArticle $article,
        public readonly bool $isNewArticle,
        public readonly ?string $matchedBy,
        public readonly array $variables,
        public readonly string $summary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'article_id' => $this->article?->id,
            'is_new_article' => $this->isNewArticle,
            'matched_by' => $this->matchedBy,
            'summary' => $this->summary,
            'variables' => $this->variables,
        ];
    }
}
