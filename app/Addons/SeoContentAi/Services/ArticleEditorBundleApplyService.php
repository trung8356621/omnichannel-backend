<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;

final class ArticleEditorBundleApplyService
{
    public function __construct(
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleMediaLocalService $mediaLocal,
        private readonly WordPressArticleContentService $wpContent,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     */
    public function apply(SeoArticle $article, array $bundle, ArticleEditorSaveContext $context): void
    {
        $categoryIds = $bundle['category_ids'] ?? null;
        if (is_array($categoryIds)) {
            $this->applyCategories($article, $context, $categoryIds);
        }

        $faqs = $bundle['faqs'] ?? null;
        if (is_array($faqs) && ! $this->shouldSkipMalformedFaqsBundle($faqs)) {
            $this->faqEditor->saveFromEditor($article, $faqs);
        }

        $featuredImage = $bundle['featured_image'] ?? null;
        if (is_array($featuredImage) && trim((string) ($featuredImage['url'] ?? '')) !== '') {
            $this->persistFeaturedImage($article, $context, $featuredImage);
        }

        $productAlbum = $bundle['product_album'] ?? null;
        if (is_array($productAlbum)) {
            $this->persistProductAlbum($article, $context, $productAlbum);
        }

        $this->persistSeoMetaFields($article, $context);
        $this->persistArticlePostTypeMeta($article, $context->postType);
    }

    /**
     * @param  list<int|string>  $categoryIds
     */
    private function applyCategories(SeoArticle $article, ArticleEditorSaveContext $context, array $categoryIds): void
    {
        $ids = collect($categoryIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($this->isTaxonomyEntity($article, $context->postType)) {
            $parentId = $ids[0] ?? 0;
            if ($parentId <= 0) {
                $article->articleMetas()->where('meta_key', 'wp_parent_id')->delete();
            } else {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_parent_id'],
                    ['meta_value' => (string) $parentId],
                );
            }

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'category_ids'],
            ['meta_value' => json_encode($ids, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function persistFeaturedImage(SeoArticle $article, ArticleEditorSaveContext $context, array $item): void
    {
        if ($this->supportsProductGallery($article, $context->postType)) {
            return;
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return;
        }

        $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
        $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? 0));
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

        if ($localRefId <= 0) {
            $localRefId = $this->mediaLocal->resolveLocalRefIdFromImageUrl(
                (int) ($article->site_id ?? 0),
                $url,
            );
        }

        if ($localRefId > 0) {
            $this->mediaLocal->applyFeaturedLocal($article, $localRefId, $url);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function persistProductAlbum(SeoArticle $article, ArticleEditorSaveContext $context, array $items): void
    {
        if (! $this->supportsProductGallery($article, $context->postType)) {
            return;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $album = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
            $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? $item['id'] ?? 0));
            $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

            if ($localRefId <= 0) {
                $localRefId = $this->mediaLocal->resolveLocalRefIdFromImageUrl($siteId, $url);
            }

            $album[] = [
                'id' => $localRefId,
                'url' => $url,
            ];
        }

        $this->mediaLocal->saveProductAlbumLocal($article, $album);
    }

    private function persistSeoMetaFields(SeoArticle $article, ArticleEditorSaveContext $context): void
    {
        $seoDescription = trim($context->seoMetaDescription);

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            if ($seoDescription === '') {
                $article->articleMetas()->where('meta_key', $key)->delete();

                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $seoDescription],
            );
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId > 0 && auth()->id() !== null) {
            KeywordFocusAttach::syncMainKeyword(
                $article,
                $siteId,
                (int) auth()->id(),
                trim($context->focusKeyword),
            );
        }
    }

    private function persistArticlePostTypeMeta(SeoArticle $article, string $postType): void
    {
        $normalized = SeoProjectTask::normalizePostType($postType);

        $wpSlug = match ($normalized) {
            SeoProjectTask::POST_TYPE_PRODUCT => 'product',
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => 'product_cat',
            SeoProjectTask::POST_TYPE_CATEGORY => 'category',
            default => 'post',
        };

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_type'],
            ['meta_value' => $wpSlug],
        );

        if (in_array($wpSlug, ['product_cat', 'category'], true)) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_entity'],
                ['meta_value' => 'term'],
            );
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_taxonomy'],
                ['meta_value' => $wpSlug],
            );

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_entity'],
            ['meta_value' => 'post'],
        );
        $article->articleMetas()->where('meta_key', 'wp_taxonomy')->delete();
    }

    /**
     * @param  list<mixed>  $faqs
     */
    private function shouldSkipMalformedFaqsBundle(array $faqs): bool
    {
        foreach ($faqs as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (
                array_key_exists('text', $row)
                && ! array_key_exists('answer', $row)
                && ! array_key_exists('question', $row)
            ) {
                return true;
            }
        }

        return false;
    }

    private function supportsProductGallery(SeoArticle $article, string $postType): bool
    {
        $type = strtolower(SeoProjectTask::normalizePostType($postType));

        if (! in_array($type, ['product', 'e-commerce'], true)) {
            return false;
        }

        return ! $this->wpContent->isTaxonomyRecord($article);
    }

    private function isTaxonomyEntity(SeoArticle $article, string $postType): bool
    {
        if ($this->wpContent->isTaxonomyRecord($article)) {
            return true;
        }

        $type = SeoProjectTask::normalizePostType($postType);

        return in_array($type, [SeoProjectTask::POST_TYPE_CATEGORY, SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY], true);
    }
}
