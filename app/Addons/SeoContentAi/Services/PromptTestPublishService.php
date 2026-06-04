<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\MarkdownOutlineParser;
use App\Addons\SeoContentAi\Support\MarkdownSemanticKeywordsParser;

final class PromptTestPublishService
{
    public function __construct(
        private readonly MarkdownOutlineParser $outlineParser,
        private readonly MarkdownSemanticKeywordsParser $keywordsParser,
        private readonly ArticleMarkdownToHtmlService $markdownHtml,
    ) {}

    /**
     * @param  array<string, string>  $variables
     * @return array{success: bool, message: string}
     */
    public function publishSkeleton(SeoArticle $article, string $aiOutput, array $variables = []): array
    {
        $markdown = trim($aiOutput);
        if ($markdown === '') {
            return ['success' => false, 'message' => 'Kết quả AI trống.'];
        }

        $this->persistOutlineAndKeywords($article, $markdown);
        $this->syncFocusKeyword($article, $variables, $markdown);

        return [
            'success' => true,
            'message' => 'Đã lưu sườn bài (dàn ý + từ khóa ngữ nghĩa) vào meta bài viết #' . $article->id . '.',
        ];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{success: bool, message: string}
     */
    public function publishArticle(SeoArticle $article, string $aiOutput, array $variables = []): array
    {
        $markdown = trim($aiOutput);
        if ($markdown === '') {
            return ['success' => false, 'message' => 'Kết quả AI trống.'];
        }

        $this->persistOutlineAndKeywords($article, $markdown);
        $this->syncFocusKeyword($article, $variables, $markdown);

        $import = app(ArticleContentFaqService::class)->convertMarkdownImport($markdown);

        $cta = app(ArticleCtaPlaceholderService::class)->applyForPublish(
            (int) $article->site_id > 0 ? (int) $article->site_id : null,
            $import['html'],
            $import['faqs'],
        );
        $html = $cta['html'];
        $faqs = $cta['faqs'];

        if ($faqs !== []) {
            app(SeoFaqPersistenceService::class)->persistForArticle($article, $faqs);
        }

        $h1Title = trim((string) ($import['h1_title'] ?? ''));
        $title = $h1Title !== ''
            ? $h1Title
            : $this->resolveTitle($variables, $markdown, $article);
        $this->persistMetaDescription($article, $import['meta_description']);
        if ($h1Title !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_title'],
                ['meta_value' => $h1Title],
            );
        }

        $article->update([
            'title' => $title,
            'body' => $html,
            'user_id' => auth()->id(),
        ]);

        app(ArticlePostImagesService::class)->syncFromHtml($article->fresh(), $html);
        app(SeoAnalyzerService::class)->analyze($article->fresh());

        if ($faqs !== []) {
            app(WordPressFaqSyncService::class)->syncForArticle($article->fresh());
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Đã tạo nội dung bài «%s» từ dàn ý (HTML trong DB). Mở editor để chỉnh và đồng bộ WP.',
                $title,
            ),
        ];
    }

    private function persistMetaDescription(SeoArticle $article, ?string $metaDescription): void
    {
        $metaDescription = trim((string) $metaDescription);
        if ($metaDescription === '') {
            return;
        }

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $metaDescription],
            );
        }
    }

    private function persistOutlineAndKeywords(SeoArticle $article, string $markdown): void
    {
        $outlineJson = $this->outlineParser->parse($markdown);
        $keywordGroups = $this->keywordsParser->parse($markdown);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_article_outline'],
            ['meta_value' => $markdown],
        );

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_outline_json'],
            [
                'meta_value' => json_encode($outlineJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        );

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_semantic_keywords'],
            [
                'meta_value' => json_encode($keywordGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function syncFocusKeyword(SeoArticle $article, array $variables, string $markdown): void
    {
        $phrase = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($phrase === '') {
            $phrase = trim((string) ($variables['post_title'] ?? ''));
        }

        if ($phrase === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => $phrase],
        );

        KeywordFocusAttach::attachMainKeyword(
            $article,
            (int) $article->site_id,
            (int) auth()->id(),
            $phrase,
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function resolveTitle(array $variables, string $markdown, SeoArticle $article): string
    {
        $fromVar = trim((string) ($variables['post_title'] ?? ''));
        if ($fromVar !== '') {
            return $fromVar;
        }

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^#\s+(.+)$/u', trim($line), $matches) === 1) {
                return trim($matches[1]);
            }
        }

        $firstH2 = $this->outlineParser->parse($markdown)['sections'][0]['title'] ?? '';

        if ($firstH2 !== '') {
            return $firstH2;
        }

        return (string) ($article->title ?: 'Bài viết mới');
    }
}
