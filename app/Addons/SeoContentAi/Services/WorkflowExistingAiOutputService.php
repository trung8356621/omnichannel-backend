<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPrompt;

final class WorkflowExistingAiOutputService
{
    public const TYPE_OUTLINE = 'outline';

    public const TYPE_CONTENT = 'content';

    /**
     * @param  array<string, mixed>  $node
     * @return null|array{type: string, output: string, message: string}
     */
    public function resolve(
        array $node,
        SeoPrompt $prompt,
        ?SeoArticle $article,
        bool $allowReuse = true,
    ): ?array {
        if (! $allowReuse) {
            return null;
        }

        if (! $article instanceof SeoArticle) {
            return null;
        }

        $type = $this->outputType($node, $prompt);
        if ($type === null) {
            return null;
        }

        if ($type === self::TYPE_CONTENT) {
            $body = trim((string) ($article->body ?? ''));

            return $body !== ''
                ? [
                    'type' => self::TYPE_CONTENT,
                    'output' => $body,
                    'message' => 'Bỏ qua AI: bài viết đã có nội dung.',
                ]
                : null;
        }

        if (! $article->relationLoaded('articleMetas')) {
            $article->load('articleMetas');
        }
        $outline = trim((string) (
            $article->articleMetas
                ->firstWhere('meta_key', 'seo_article_outline')
                ?->meta_value ?? ''
        ));

        return $outline !== ''
            ? [
                'type' => self::TYPE_OUTLINE,
                'output' => $outline,
                'message' => 'Bỏ qua AI: bài viết đã có dàn ý.',
            ]
            : null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function outputType(array $node, SeoPrompt $prompt): ?string
    {
        if ((bool) ($node['data']['mergeOutlineToSave'] ?? false)) {
            return self::TYPE_CONTENT;
        }

        $name = mb_strtolower(trim((string) $prompt->name));
        if ($name !== '' && (str_contains($name, 'dàn ý') || str_contains($name, 'outline'))) {
            return self::TYPE_OUTLINE;
        }

        return null;
    }
}
