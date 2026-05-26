<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;

/**
 * Theo dõi bài đã chỉnh trên SEO chưa đồng bộ WordPress và xung đột khi WP đẩy dữ liệu mới.
 */
final class ArticleWordPressSyncFlagService
{
    public const META_LOCAL_EDIT_PENDING = 'seo_local_edit_pending';

    /** Flag = true: WordPress đã gửi bản mới nhưng Laravel không ghi đè vì bài đang chỉnh local. */
    public const META_WP_DATA_OUT_OF_SYNC = 'wp_data_out_of_sync';

    public function markLocalEditPending(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_LOCAL_EDIT_PENDING, true);
    }

    public function clearLocalEditPending(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_LOCAL_EDIT_PENDING, false);
    }

    public function hasLocalEditPending(SeoArticle $article): bool
    {
        return $this->readFlag($article, self::META_LOCAL_EDIT_PENDING);
    }

    public function markDataOutOfSync(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_WP_DATA_OUT_OF_SYNC, true);
    }

    public function clearDataOutOfSync(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_WP_DATA_OUT_OF_SYNC, false);
    }

    public function hasDataOutOfSync(SeoArticle $article): bool
    {
        return $this->readFlag($article, self::META_WP_DATA_OUT_OF_SYNC);
    }

    public function clearAll(SeoArticle $article): void
    {
        $this->clearLocalEditPending($article);
        $this->clearDataOutOfSync($article);
    }

    public function hasLocalEditorContent(SeoArticle $article): bool
    {
        return trim((string) ($article->body ?? '')) !== '';
    }

    public function shouldBlockWordPressImport(SeoArticle $article): bool
    {
        if (! $this->hasLocalEditPending($article)) {
            return false;
        }

        if (! $this->hasLocalEditorContent($article)) {
            $this->clearLocalEditPending($article);

            return false;
        }

        return true;
    }

    public function decodeWordPressText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function setFlag(SeoArticle $article, string $key, bool $active): void
    {
        if ($active) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => '1'],
            );

            return;
        }

        $article->articleMetas()->where('meta_key', $key)->delete();
    }

    private function readFlag(SeoArticle $article, string $key): bool
    {
        $article->loadMissing('articleMetas');

        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;

        if ($value === null || $value === '') {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }
}
