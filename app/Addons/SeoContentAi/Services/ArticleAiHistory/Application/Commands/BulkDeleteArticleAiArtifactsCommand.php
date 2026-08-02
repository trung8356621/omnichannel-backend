<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleAiHistory\Application\Commands;

use App\Addons\SeoContentAi\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;

/**
 * Xoá (tombstone) nhiều artifact AI khỏi lịch sử bài viết cùng lúc.
 *
 * @param  list<string>  $artifactRefs
 * @param  list<int>  $accessibleProjectIds
 */
final class BulkDeleteArticleAiArtifactsCommand implements ArticleAiHistoryCommand
{
    public function __construct(
        public readonly int $articleId,
        public readonly array $artifactRefs,
        public readonly array $accessibleProjectIds,
        public readonly int $userId,
        public readonly bool $confirmPreviouslyApplied = false,
        public readonly ?string $reason = null,
    ) {}

    public function name(): string
    {
        return 'article_ai_history.bulk_delete';
    }
}
