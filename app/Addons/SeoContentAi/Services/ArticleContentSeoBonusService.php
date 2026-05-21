<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;

final class ArticleContentSeoBonusService
{
    public const MAX_POINTS_PER_ITEM = 10;

    public function __construct(
        private readonly WorkflowParserService $workflowParser,
    ) {}

    /**
     * @return array{
     *     faq_count: int,
     *     total_bonus: int,
     *     items: array{
     *         featured_snippet: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *         faq: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *     },
     * }
     */
    public function resolveForArticle(SeoArticle $article, ?string $content = null): array
    {
        $content = $content ?? (string) ($article->body ?? '');
        $faqs = $this->resolveFaqsForScoring($article, $content);

        $checklist = $this->loadStoredChecklist($article);
        if ($checklist === null || trim($content) !== '') {
            $scoreData = $this->workflowParser->calculateSeoScoreFromContent($content, $faqs);
            $checklist = is_array($scoreData['checklist'] ?? null) ? $scoreData['checklist'] : [];
        }

        return $this->formatBonusPayload($checklist, $this->countArticleFaqs($article));
    }

    /**
     * @return array{
     *     faq_count: int,
     *     total_bonus: int,
     *     items: array{
     *         featured_snippet: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *         faq: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *     },
     * }
     */
    public function resolveFromContent(SeoArticle $article, string $content): array
    {
        $faqs = $this->resolveFaqsForScoring($article, $content);
        $scoreData = $this->workflowParser->calculateSeoScoreFromContent($content, $faqs);
        $checklist = is_array($scoreData['checklist'] ?? null) ? $scoreData['checklist'] : [];

        return $this->formatBonusPayload($checklist, $this->countArticleFaqs($article));
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function resolveFaqsForScoring(SeoArticle $article, string $content): array
    {
        $article->loadMissing(['faqs', 'articleMetas']);

        $dbFaqs = $article->resolveFaqs();
        if (trim($content) === '') {
            return $dbFaqs;
        }

        $contentFaqs = $this->workflowParser->parseFaqsFromContent($content);

        return count($contentFaqs) > count($dbFaqs) ? $contentFaqs : $dbFaqs;
    }

    /**
     * @return array<string, array{passed: bool, points: int, message: string}>|null
     */
    private function loadStoredChecklist(SeoArticle $article): ?array
    {
        if (! $article->relationLoaded('articleMetas')) {
            $article->loadMissing('articleMetas');
        }

        /** @var ArticleMeta|null $meta */
        $meta = $article->articleMetas->firstWhere('meta_key', 'seo_scoring_details');
        if ($meta === null || ! is_string($meta->meta_value) || trim($meta->meta_value) === '') {
            return null;
        }

        $decoded = json_decode($meta->meta_value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, array{passed?: bool, points?: int, message?: string}>  $checklist
     * @return array{
     *     faq_count: int,
     *     total_bonus: int,
     *     items: array{
     *         featured_snippet: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *         faq: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *     },
     * }
     */
    private function countArticleFaqs(SeoArticle $article): int
    {
        $article->loadMissing('faqs');

        return (int) $article->faqs->count();
    }

    private function formatBonusPayload(array $checklist, int $faqCount): array
    {
        $table = $checklist['table'] ?? [];
        $faq = $checklist['faq'] ?? [];

        if ($faqCount === 0) {
            $faq = [
                'passed' => false,
                'points' => 0,
                'message' => 'Thiếu phần FAQ chuẩn (chưa tách / lưu FAQ)',
            ];
        }

        $featuredItem = $this->formatItem(
            'featured_snippet',
            'FEATURED SNIPPET',
            $table,
        );
        $faqItem = $this->formatItem('faq', 'FAQ', $faq, $faqCount);

        return [
            'faq_count' => $faqCount,
            'total_bonus' => $featuredItem['points'] + $faqItem['points'],
            'items' => [
                'featured_snippet' => $featuredItem,
                'faq' => $faqItem,
            ],
        ];
    }

    /**
     * @param  array{passed?: bool, points?: int, message?: string}  $raw
     * @return array{key: string, label: string, points: int, max_points: int, passed: bool, message: string}
     */
    private function formatItem(string $key, string $label, array $raw, ?int $faqCount = null): array
    {
        $passed = (bool) ($raw['passed'] ?? false);
        $points = (int) ($raw['points'] ?? 0);
        $message = trim((string) ($raw['message'] ?? ''));

        if ($message === '') {
            $message = $passed
                ? $label . ' đạt chuẩn.'
                : $label . ' chưa đạt.';
        }

        if ($key === 'faq' && $faqCount !== null && $faqCount > 0 && ! str_contains($message, (string) $faqCount)) {
            $message = preg_replace(
                '/\(\d+ câu hỏi\)/u',
                '(' . $faqCount . ' câu hỏi)',
                $message,
            ) ?? $message;

            if (! preg_match('/\d+\s*câu\s*hỏi/iu', $message)) {
                $message = rtrim($message, '.') . ' (' . $faqCount . ' câu hỏi).';
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'points' => $passed ? self::MAX_POINTS_PER_ITEM : $points,
            'max_points' => self::MAX_POINTS_PER_ITEM,
            'passed' => $passed,
            'message' => $message,
        ];
    }
}
