<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners;

use App\Addons\SeoContentAi\Models\SeoArticleRevision;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;

/**
 * Dọn Editor History (SaaS). Sau sync/publish, WordPress Revision là nguồn lịch sử.
 */
final class EditorRevisionWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'editor_revision';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $deleted = SeoArticleRevision::query()
            ->whereIn('article_id', $context->articleIds())
            ->delete();
        $context->bumpStat('editor_revisions_deleted', (int) $deleted);
    }
}
