<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleAiHistory\Application\Commands;

use App\Addons\SeoContentAi\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;

/**
 * List các artifact AI (outline/content...) trong lịch sử của một bài viết.
 *
 * @param  list<int>  $accessibleProjectIds
 * @param  array{type?: string, status?: string, include_deleted?: bool}  $filters
 */
final class ListArticleAiHistoryCommand implements ArticleAiHistoryCommand
{
    public function __construct(
        public readonly int $articleId,
        public readonly array $accessibleProjectIds,
        public readonly array $filters = [],
    ) {}

    public function name(): string
    {
        return 'article_ai_history.list';
    }
}
