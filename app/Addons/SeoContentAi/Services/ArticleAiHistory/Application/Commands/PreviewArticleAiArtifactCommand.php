<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleAiHistory\Application\Commands;

use App\Addons\SeoContentAi\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;

/**
 * Xem trước (sanitized) nội dung artifact AI trước khi apply.
 *
 * @param  list<int>  $accessibleProjectIds
 */
final class PreviewArticleAiArtifactCommand implements ArticleAiHistoryCommand
{
    public function __construct(
        public readonly int $articleId,
        public readonly string $artifactRef,
        public readonly array $accessibleProjectIds,
    ) {}

    public function name(): string
    {
        return 'article_ai_history.preview';
    }
}
