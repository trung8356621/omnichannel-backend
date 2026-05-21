<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;

final class WordPressFaqSyncService
{
    /**
     * Đẩy bài viết lên WordPress (FAQ + nội dung). Dùng cho workflow tự động.
     */
    public function syncForArticle(SeoArticle $article): bool
    {
        $result = app(WordPressArticleSyncService::class)->syncForArticle($article);

        return $result['success'];
    }
}
