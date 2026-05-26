<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\FaqRowNormalizer;

/**
 * Khôi phục nội dung bài (kèm khối FAQ gốc) từ WordPress khi panel FAQ bị xóa sạch.
 */
final class ArticleFaqWordPressRestoreService
{
    public function __construct(
        private readonly WordPressArticleContentService $wordpressContent,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly ArticleFaqExtractDebugService $extractDebug,
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
    ) {
    }

    /**
     * @return array{restored: bool, editor_html: ?string, message: string}
     */
    public function restoreWhenFaqsCleared(SeoArticle $article): array
    {
        if ((int) ($article->wp_post_id ?? 0) <= 0) {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Bài chưa liên kết WordPress.',
            ];
        }

        $post = $this->wordpressContent->fetchFromWordPress($article, importFaqs: false);
        $html = $this->resolveRestoredEditorHtml($article, $post);

        if ($html === '') {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Không lấy được nội dung gốc từ WordPress.',
            ];
        }

        $this->contentFaq->persistArticleBodyHtml($article, $html);
        $this->extractDebug->clear($article);

        return [
            'restored' => true,
            'editor_html' => $html,
            'message' => 'Đã khôi phục nội dung gốc từ WordPress (kèm khối FAQ trong editor).',
        ];
    }

    /**
     * Lấy lại toàn bộ bài gốc từ WordPress (editor + xóa FAQ panel).
     *
     * @return array{
     *     restored: bool,
     *     editor_html: ?string,
     *     title: ?string,
     *     slug: ?string,
     *     message: string,
     * }
     */
    public function restoreFullArticleFromWordPress(SeoArticle $article): array
    {
        if ((int) ($article->wp_post_id ?? 0) <= 0) {
            return [
                'restored' => false,
                'editor_html' => null,
                'title' => null,
                'slug' => null,
                'message' => 'Bài chưa liên kết WordPress.',
            ];
        }

        $post = $this->wordpressContent->fetchFromWordPress($article, importFaqs: false);

        if ($post === []) {
            return [
                'restored' => false,
                'editor_html' => null,
                'title' => null,
                'slug' => null,
                'message' => 'Không lấy được nội dung từ WordPress.',
            ];
        }

        $wpFaqs = is_array($post['faqs'] ?? null) ? $post['faqs'] : [];
        if ($wpFaqs !== []) {
            $import = app(ArticleFaqWordPressImportService::class)->importFromWordPressSyncItem($article, $post);
            $article->refresh();

            $title = trim((string) ($post['post_title'] ?? ''));
            $slug = trim((string) ($post['slug'] ?? ''));

            if ($title !== '') {
                $article->update(['title' => $title]);
            }

            return [
                'restored' => true,
                'editor_html' => trim((string) ($article->body ?? '')),
                'title' => $title !== '' ? $title : null,
                'slug' => $slug !== '' ? $slug : null,
                'message' => ($import['imported'] ?? false)
                    ? 'Đã khôi phục bài viết và ' . ($import['faq_count'] ?? 0) . ' FAQ từ WordPress.'
                    : 'Đã khôi phục bài viết từ WordPress.',
            ];
        }

        $article->faqs()->delete();
        $article->articleMetas()->where('meta_key', 'seo_article_faqs')->delete();

        $html = $this->resolveRestoredEditorHtml($article, $post);

        if ($html === '') {
            return [
                'restored' => false,
                'editor_html' => null,
                'title' => null,
                'slug' => null,
                'message' => 'Không lấy được nội dung gốc từ WordPress.',
            ];
        }

        $this->contentFaq->persistArticleBodyHtml($article, $html);
        $this->extractDebug->clear($article);

        $title = $this->syncFlags->decodeWordPressText((string) ($post['post_title'] ?? ''));
        $slug = trim((string) ($post['slug'] ?? ''));

        $this->applyRestoredTitleAndSlug($article, $title, $slug);
        $this->syncFlags->clearAll($article);

        return [
            'restored' => true,
            'editor_html' => $html,
            'title' => $title !== '' ? $title : null,
            'slug' => $slug !== '' ? $slug : null,
            'message' => 'Đã khôi phục bài viết gốc từ WordPress.',
        ];
    }

    private function applyRestoredTitleAndSlug(SeoArticle $article, string $title, string $slug): void
    {
        if ($title !== '') {
            $article->update(['title' => $title]);
        }

        if ($slug !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_slug'],
                ['meta_value' => $slug],
            );
        }
    }

    /**
     * Khi xóa một/một vài FAQ khỏi panel: so sánh với bản gốc WP, khôi phục block đã bỏ (vd. «Xem thêm:»),
     * chỉ giữ [omi_faq] cho các FAQ còn lại trong panel.
     *
     * @param  list<array{question?: string, answer?: string, more?: string|null}>  $remainingFaqs
     * @return array{restored: bool, editor_html: ?string, message: string}
     */
    public function restoreAfterFaqRemoved(SeoArticle $article, array $remainingFaqs): array
    {
        if ((int) ($article->wp_post_id ?? 0) <= 0) {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Bài chưa liên kết WordPress.',
            ];
        }

        $post = $this->wordpressContent->fetchFromWordPress($article, importFaqs: false);
        $sourceHtml = $this->resolveRestoredEditorHtml($article, $post);

        if ($sourceHtml === '') {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Không lấy được nội dung gốc từ WordPress.',
            ];
        }

        $panelQuestions = [];
        foreach ($remainingFaqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $panelQuestions[] = $question;
            }
        }

        $sourceHtml = $this->workflowParser->stripFaqShortcodeArtifacts($sourceHtml);
        $sourceHtml = $this->workflowParser->preprocessHtmlForFaqExtraction($sourceHtml);

        $editorHtml = $this->workflowParser->stripPanelFaqsFromContent($sourceHtml, $panelQuestions);

        if ($editorHtml === '') {
            $editorHtml = $sourceHtml;
        } elseif ($panelQuestions !== [] && ! str_contains($editorHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)) {
            $editorHtml = $sourceHtml;
        }

        $this->contentFaq->persistArticleBodyHtml($article, $editorHtml);
        $this->extractDebug->clear($article);

        return [
            'restored' => true,
            'editor_html' => $editorHtml,
            'message' => 'Đã khôi phục phần nội dung gốc từ WordPress. FAQ còn lại vẫn trong panel.',
        ];
    }

    public function persistWordPressSourceSnapshot(SeoArticle $article, string $html): void
    {
        $html = trim($html);
        if ($html === '') {
            return;
        }

        if (
            str_contains($html, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)
            && $this->workflowParser->parseFaqsFromContent($html) === []
        ) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_content_source'],
            ['meta_value' => $html],
        );
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function resolveRestoredEditorHtml(SeoArticle $article, array $post): string
    {
        $article->loadMissing('articleMetas');

        $snapshot = trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'wp_post_content_source')?->meta_value ?? ''
        ));

        if ($snapshot !== '' && $this->workflowParser->parseFaqsFromContent($snapshot) !== []) {
            return $snapshot;
        }

        $content = trim((string) ($post['post_content'] ?? ''));
        if ($content === '') {
            $scoring = is_array($post['scoring'] ?? null) ? $post['scoring'] : [];
            $content = trim((string) ($scoring['body'] ?? ''));
        }

        if ($content === '' && $snapshot !== '') {
            return $snapshot;
        }

        if ($content === '') {
            return '';
        }

        $wpFaqs = $this->normalizeWordPressFaqRows($post['faqs'] ?? null);
        $content = $this->workflowParser->stripFaqShortcodeArtifacts($content);

        if ($this->workflowParser->parseFaqsFromContent($content) !== []) {
            return $content;
        }

        if ($wpFaqs !== []) {
            $headingSource = $snapshot !== '' ? $snapshot : $content;
            $foundHeading = $this->workflowParser->findFaqSectionHeadingInContent($headingSource);
            $heading = is_array($foundHeading) ? (string) ($foundHeading['text'] ?? 'FAQ') : 'FAQ';
            $faqBlock = $this->workflowParser->buildFaqSectionHtmlForEditor($wpFaqs, $heading);

            return trim($content) . ($content !== '' ? "\n\n" : '') . $faqBlock;
        }

        if ($snapshot !== '') {
            return $snapshot;
        }

        return $content;
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function normalizeWordPressFaqRows(mixed $faqs): array
    {
        return FaqRowNormalizer::normalizeList($faqs);
    }
}
