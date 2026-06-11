<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticleHeading;
use Illuminate\Support\Str;

/**
 * Dò trùng lặp cho cả một dàn ý (batch) ngay sau khi AI sinh outline:
 * 0. Heading nằm trong Skip List (settings `outline_skip_words`) -> bỏ qua ngay.
 * 1. Exact match qua `heading_slug` (gom 1 query whereIn cho toàn bộ dàn ý),
 *    chỉ so với heading CÙNG LEVEL (H2 vs H2, H3 vs H3...).
 * 2. Heading nào không trùng exact thì chạy tiếp MySQL FULLTEXT
 *    (Natural Language Mode, cùng level) với ngưỡng score, sau đó double-check
 *    bằng word overlap (PHP) để loại các ca chỉ trùng 1-2 từ phổ biến.
 *
 * Không gọi API AI ở bước này.
 */
class HeadingDuplicateCheckerService
{
    /** Score FTS tối thiểu để lọt vào vòng double-check. */
    private const SEMANTIC_SCORE_THRESHOLD = 2.0;

    /** Tỷ lệ từ trùng tối thiểu (intersection / số từ của chuỗi dài hơn). */
    private const WORD_OVERLAP_THRESHOLD = 0.6;

    public function __construct(
        private readonly HeadingDuplicateCheckService $duplicateCheck,
        private readonly SeoOverviewSettingsService $overviewSettings,
    ) {}

    /**
     * Kiểm tra mảng heading mới so với toàn bộ heading đã lưu trong site.
     *
     * @param  array<int|string, array{text: string, level: int}>  $headings
     *         map key => ['text' => ..., 'level' => 2|3|4]. Key tùy caller
     *         (vd: heading id đã lưu, hoặc index trong dàn ý Markdown vừa parse).
     * @return array{is_duplicate: bool, duplicates: list<array<string, mixed>>}
     */
    public function check(array $headings, int $siteId, ?int $excludeArticleId = null): array
    {
        $skipWords = $this->overviewSettings->getOutlineSkipWords();

        $normalized = [];
        foreach ($headings as $key => $item) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($item['text'] ?? '')) ?? '');
            if ($text === '') {
                continue;
            }

            // Skip List: tiêu đề được phép trùng -> không dò, không báo đỏ.
            if ($this->isSkipped($text, $skipWords)) {
                continue;
            }

            $normalized[$key] = [
                'text' => $text,
                'slug' => Str::slug($text),
                'level' => (int) ($item['level'] ?? 0),
            ];
        }

        if ($normalized === []) {
            return ['is_duplicate' => false, 'duplicates' => []];
        }

        $exactByLevelSlug = $this->exactMatchesByLevelSlug($normalized, $siteId, $excludeArticleId);

        $duplicates = [];
        foreach ($normalized as $key => $item) {
            $exact = $exactByLevelSlug[$item['level'] . ':' . $item['slug']] ?? null;
            if ($exact !== null) {
                $duplicates[] = $this->formatDuplicate($key, $item['text'], $exact, 'exact');

                continue;
            }

            // Vòng 1: FTS (cùng level) lấy tập ứng viên; vòng 2: double-check word overlap bằng PHP.
            $semantic = $this->duplicateCheck
                ->checkSemanticMatch($item['text'], $siteId, $excludeArticleId, $item['level'])
                ->filter(
                    fn (SeoArticleHeading $row): bool => (float) ($row->getAttribute('score') ?? 0) > self::SEMANTIC_SCORE_THRESHOLD,
                )
                ->first(
                    fn (SeoArticleHeading $row): bool => $this->wordOverlapRatio($item['text'], (string) $row->heading_text) >= self::WORD_OVERLAP_THRESHOLD,
                );

            if ($semantic !== null) {
                $duplicates[] = $this->formatDuplicate($key, $item['text'], $semantic, 'semantic');
            }
        }

        return [
            'is_duplicate' => $duplicates !== [],
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Heading có nằm trong Skip List không (so sánh lowercase, exact hoặc chứa cụm skip).
     *
     * @param  list<string>  $skipWords  đã lowercase sẵn từ settings
     */
    private function isSkipped(string $text, array $skipWords): bool
    {
        if ($skipWords === []) {
            return false;
        }

        $lower = mb_strtolower($text, 'UTF-8');

        foreach ($skipWords as $skipWord) {
            if ($skipWord !== '' && str_contains($lower, $skipWord)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gom exact match của toàn bộ slug trong 1 query, ràng buộc cùng level.
     *
     * @param  array<int|string, array{text: string, slug: string, level: int}>  $normalized
     * @return array<string, SeoArticleHeading> "level:slug" => heading trùng đầu tiên
     */
    private function exactMatchesByLevelSlug(array $normalized, int $siteId, ?int $excludeArticleId): array
    {
        $slugs = array_values(array_unique(array_filter(
            array_column($normalized, 'slug'),
            static fn (string $slug): bool => $slug !== '',
        )));
        $levels = array_values(array_unique(array_column($normalized, 'level')));

        if ($slugs === [] || $levels === []) {
            return [];
        }

        $query = SeoArticleHeading::query()
            ->with('article:id,title,slug,site_id')
            ->whereIn('heading_slug', $slugs)
            ->whereIn('level', $levels)
            ->whereHas('article', function ($sub) use ($siteId): void {
                $sub->where('site_id', $siteId);
            });

        if ($excludeArticleId !== null && $excludeArticleId > 0) {
            $query->where('article_id', '!=', $excludeArticleId);
        }

        return $query
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (SeoArticleHeading $row): string => $row->level . ':' . $row->heading_slug)
            ->map(static fn ($group) => $group->first())
            ->all();
    }

    /**
     * Tỷ lệ từ trùng giữa 2 heading: |giao| / số từ (unique) của chuỗi dài hơn.
     * Loại bỏ token thuần số (đánh số mục lục "1.", "2.") để tránh nhiễu.
     */
    private function wordOverlapRatio(string $a, string $b): float
    {
        $wordsA = $this->tokenize($a);
        $wordsB = $this->tokenize($b);

        if ($wordsA === [] || $wordsB === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($wordsA, $wordsB));
        $denominator = max(count($wordsA), count($wordsB));

        return $intersection / $denominator;
    }

    /**
     * @return list<string> danh sách từ unique, lowercase, bỏ dấu câu và token thuần số
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1);

        return array_values(array_unique($words));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDuplicate(
        int|string $key,
        string $originalText,
        SeoArticleHeading $matched,
        string $matchType,
    ): array {
        return [
            'original_key' => $key,
            'original_heading' => $originalText,
            'matched_heading' => (string) $matched->heading_text,
            'matched_heading_id' => (int) $matched->id,
            'matched_article_id' => (int) $matched->article_id,
            'matched_article_title' => (string) ($matched->article?->title ?? ''),
            'match_type' => $matchType,
            'score' => $matchType === 'semantic'
                ? round((float) ($matched->getAttribute('score') ?? 0), 4)
                : null,
        ];
    }
}
