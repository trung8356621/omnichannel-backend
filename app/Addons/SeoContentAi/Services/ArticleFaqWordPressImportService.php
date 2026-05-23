<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;

/**
 * Import FAQ khi đồng bộ / mở bài từ WordPress (meta WP hoặc quét post_content).
 */
final class ArticleFaqWordPressImportService
{
    public function __construct(
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleFaqExtractDebugService $extractDebug,
        private readonly ArticleContentFaqService $contentFaq,
    ) {
    }

    /**
     * Quét FAQ từ nội dung WP/HTML; ưu tiên parser HTML hơn meta wp_faqs (thường thiếu câu).
     *
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: ?string,
     *     extract_debug: array<string, mixed>|null,
     * }
     */
    public function importWhenPanelEmpty(SeoArticle $article, ?string $html = null): array
    {
        $article->loadMissing('faqs');
        $existingCount = $article->faqs->count();

        $html = trim($html ?? $this->resolveContentHtml($article));
        if ($html === '') {
            return $this->emptyResult($article, $existingCount);
        }

        $html = $this->workflowParser->preprocessHtmlForFaqExtraction($html);
        $htmlRows = $this->parseRowsFromHtml($html);
        $wpFaqs = $this->resolveStoredWordPressFaqs($article);
        $bestRows = count($htmlRows) >= count($wpFaqs) ? $htmlRows : $wpFaqs;

        if ($bestRows === []) {
            if ($existingCount > 0 || $this->extractDebug->isSuppressed($article)) {
                return [
                    'imported' => false,
                    'faq_count' => $existingCount,
                    'faqs' => $this->faqEditor->payloadForArticle($article),
                    'editor_html' => null,
                    'extract_debug' => null,
                ];
            }

            $diagnosis = $this->workflowParser->diagnoseManualFaqExtract($html);
            $extractDebug = $this->extractDebug->recordFromContentDiagnosis(
                $article,
                $diagnosis,
                'wp_pull_no_pairs',
                'wp_pull',
            );

            return [
                'imported' => false,
                'faq_count' => $existingCount,
                'faqs' => $this->faqEditor->payloadForArticle($article),
                'editor_html' => null,
                'extract_debug' => $extractDebug,
            ];
        }

        if ($existingCount > 0 && count($bestRows) <= $existingCount) {
            return [
                'imported' => false,
                'faq_count' => $existingCount,
                'faqs' => $this->faqEditor->payloadForArticle($article),
                'editor_html' => null,
                'extract_debug' => null,
            ];
        }

        return $this->persistImportedFaqs($article, $html, $bestRows);
    }

    /**
     * Sau đồng bộ domain / webhook từ WordPress.
     *
     * @param  array<string, mixed>  $item
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     extract_debug: array<string, mixed>|null,
     * }
     */
    public function importFromWordPressSyncItem(SeoArticle $article, array $item): array
    {
        $article->faqs()->delete();
        $this->extractDebug->clear($article);

        $content = trim((string) ($item['post_content'] ?? ''));
        if ($content === '') {
            $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];
            $content = trim((string) ($scoring['body'] ?? ''));
        }

        $content = $this->workflowParser->preprocessHtmlForFaqExtraction($content);
        $htmlRows = $this->parseRowsFromHtml($content);
        $wpFaqs = $this->normalizeWordPressFaqRows($item['faqs'] ?? null);
        $bestRows = count($htmlRows) >= count($wpFaqs) ? $htmlRows : $wpFaqs;

        if ($wpFaqs !== []) {
            $this->persistWordPressFaqsMeta($article, $wpFaqs);
        }

        if ($bestRows === []) {
            return [
                'imported' => false,
                'faq_count' => 0,
                'extract_debug' => null,
            ];
        }

        $result = $this->persistImportedFaqs($article, $content, $bestRows);

        return [
            'imported' => $result['imported'],
            'faq_count' => $result['faq_count'],
            'extract_debug' => $result['extract_debug'],
        ];
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string}>  $rows
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: string,
     *     extract_debug: null,
     * }
     */
    private function persistImportedFaqs(SeoArticle $article, string $sourceHtml, array $rows): array
    {
        $this->faqEditor->saveFromEditor($article, $rows);
        $this->extractDebug->clear($article);

        $strippedHtml = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($sourceHtml);
        if ($strippedHtml === '' || ! str_contains($strippedHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)) {
            $strippedHtml = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($sourceHtml);
        }

        if (! str_contains($strippedHtml, 'omi-faq-placeholder')
            && str_contains($strippedHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)
        ) {
            $strippedHtml = (string) preg_replace(
                '/\s*' . preg_quote(WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER, '/') . '\s*/u',
                $this->workflowParser->faqPlaceholderHtml(),
                $strippedHtml,
                1,
            );
        }

        $this->contentFaq->persistArticleBodyHtml($article, $strippedHtml);
        $article->load('faqs');

        return [
            'imported' => true,
            'faq_count' => $article->faqs->count(),
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'editor_html' => $strippedHtml,
            'extract_debug' => null,
        ];
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function parseRowsFromHtml(string $html): array
    {
        return $this->normalizeParsedRows($this->workflowParser->parseFaqsFromContent($html));
    }

    /**
     * @param  list<array<string, mixed>>  $parsed
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function normalizeParsedRows(array $parsed): array
    {
        $rows = [];

        foreach ($parsed as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $rows[] = [
                'question' => $question,
                'answer' => $answer,
                'more' => trim((string) ($faq['more'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function resolveStoredWordPressFaqs(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', 'wp_faqs')?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return $this->normalizeWordPressFaqRows(is_array($decoded) ? $decoded : null);
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function normalizeWordPressFaqRows(mixed $faqs): array
    {
        if (! is_array($faqs)) {
            return [];
        }

        $rows = [];

        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $rows[] = [
                'question' => $question,
                'answer' => $answer,
                'more' => trim((string) ($faq['more'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string}>  $faqs
     */
    private function persistWordPressFaqsMeta(SeoArticle $article, array $faqs): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_faqs'],
            ['meta_value' => json_encode($faqs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
    }

    private function resolveContentHtml(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        $article->loadMissing('articleMetas');

        return trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_post_content')?->meta_value ?? ''));
    }

    /**
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: null,
     *     extract_debug: null,
     * }
     */
    private function emptyResult(SeoArticle $article, int $existingCount): array
    {
        return [
            'imported' => false,
            'faq_count' => $existingCount,
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'editor_html' => null,
            'extract_debug' => null,
        ];
    }
}
