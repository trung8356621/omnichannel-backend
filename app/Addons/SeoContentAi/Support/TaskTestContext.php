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

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $article = null;
        $articleId = $data['article_id'] ?? null;

        if (is_numeric($articleId) && (int) $articleId > 0) {
            $article = SeoArticle::query()->find((int) $articleId);
        }

        $variables = is_array($data['variables'] ?? null) ? $data['variables'] : [];
        $normalizedVariables = [];
        foreach ($variables as $key => $value) {
            $normalizedVariables[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        return new self(
            article: $article,
            isNewArticle: (bool) ($data['is_new_article'] ?? false),
            matchedBy: is_string($data['matched_by'] ?? null) ? $data['matched_by'] : null,
            variables: $normalizedVariables,
            summary: (string) ($data['summary'] ?? ''),
        );
    }
}
