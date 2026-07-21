<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\UnauthorizedWordPressSideEffectException;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\WordPressExecutionContext;

/**
 * FAQ sync wrapper — requires explicit WordPressExecutionContext (manual or automation).
 */
final class WordPressFaqSyncService
{
    public function syncForArticle(SeoArticle $article, WordPressExecutionContext $sideEffect): bool
    {
        if ($sideEffect->origin() !== 'automation') {
            throw new UnauthorizedWordPressSideEffectException(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'FAQ sync requires AutomationWordPressContext.',
            );
        }

        $result = app(WordPressArticleSyncService::class)->syncForArticle($article, $sideEffect);

        return (bool) ($result['success'] ?? false);
    }
}
