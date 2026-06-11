<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\WpOption;

final class SeoOverviewSettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    public const OPTION_KEY = 'seo_overview_settings';

    public const KEY_FAQ_CATCH_KEYWORDS = 'faq_catch_keywords';

    public const KEY_OUTLINE_SKIP_WORDS = 'outline_skip_words';

    /** @var list<string> */
    private const DEFAULT_OUTLINE_SKIP_WORDS = [
        'giới thiệu',
        'kết luận',
        'faq',
        'câu hỏi thường gặp',
    ];

    /** @var list<string> */
    private const DEFAULT_FAQ_CATCH_KEYWORDS = [
        'faq',
        'câu hỏi thường gặp',
        'cau hoi thuong gap',
        'câu hỏi',
        'cau hoi',
        'hỏi đáp',
        'hoi dap',
        'giải đáp',
        'giai dap',
    ];

    public static function withDefaults(): self
    {
        $service = new self();
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    /**
     * @param  list<string>  $keywords
     */
    public static function withFaqCatchKeywords(array $keywords): self
    {
        $service = new self();
        $normalized = $service->normalizeKeywords($keywords);
        $service->inMemorySettings = [
            self::KEY_FAQ_CATCH_KEYWORDS => $normalized !== [] ? $normalized : self::DEFAULT_FAQ_CATCH_KEYWORDS,
        ];

        return $service;
    }

    /**
     * @return array{faq_catch_keywords: list<string>, outline_skip_words: list<string>}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->inMemorySettings;
        }

        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return $this->defaultSettings();
        }

        $keywords = $this->normalizeKeywords($data[self::KEY_FAQ_CATCH_KEYWORDS] ?? null);
        $skipWords = array_key_exists(self::KEY_OUTLINE_SKIP_WORDS, $data)
            ? $this->normalizeKeywords($data[self::KEY_OUTLINE_SKIP_WORDS])
            : self::DEFAULT_OUTLINE_SKIP_WORDS;

        return [
            self::KEY_FAQ_CATCH_KEYWORDS => $keywords !== [] ? $keywords : self::DEFAULT_FAQ_CATCH_KEYWORDS,
            self::KEY_OUTLINE_SKIP_WORDS => $skipWords,
        ];
    }

    /**
     * @return list<string>
     */
    public function getFaqCatchKeywords(): array
    {
        $keywords = $this->getSettings()[self::KEY_FAQ_CATCH_KEYWORDS];

        return $this->normalizeKeywords($keywords);
    }

    /**
     * @return list<string> các từ/tiêu đề được phép trùng (đã lowercase)
     */
    public function getOutlineSkipWords(): array
    {
        return $this->getSettings()[self::KEY_OUTLINE_SKIP_WORDS];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $keywords = $this->normalizeKeywords($settings[self::KEY_FAQ_CATCH_KEYWORDS] ?? null);
        $skipWords = array_key_exists(self::KEY_OUTLINE_SKIP_WORDS, $settings)
            ? $this->normalizeKeywords($settings[self::KEY_OUTLINE_SKIP_WORDS])
            : $this->getOutlineSkipWords();

        WpOption::set(self::OPTION_KEY, [
            self::KEY_FAQ_CATCH_KEYWORDS => $keywords !== [] ? $keywords : self::DEFAULT_FAQ_CATCH_KEYWORDS,
            self::KEY_OUTLINE_SKIP_WORDS => $skipWords,
        ], 'no');

        $this->inMemorySettings = null;
    }

    /**
     * Chuyển textarea (mỗi dòng một từ khóa) thành danh sách đã chuẩn hóa.
     */
    public function keywordsFromTextarea(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return $this->normalizeKeywords($lines);
    }

    public function keywordsToTextarea(array $keywords): string
    {
        return implode("\n", $this->normalizeKeywords($keywords));
    }

    /**
     * @return array{faq_catch_keywords: list<string>, outline_skip_words: list<string>}
     */
    private function defaultSettings(): array
    {
        return [
            self::KEY_FAQ_CATCH_KEYWORDS => self::DEFAULT_FAQ_CATCH_KEYWORDS,
            self::KEY_OUTLINE_SKIP_WORDS => self::DEFAULT_OUTLINE_SKIP_WORDS,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeKeywords(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $keywords = [];
        foreach ($raw as $item) {
            $label = trim(is_string($item) ? $item : (string) ($item['keyword'] ?? $item['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $lower = mb_strtolower($label, 'UTF-8');
            if (in_array($lower, $keywords, true)) {
                continue;
            }

            $keywords[] = $lower;
        }

        return $keywords;
    }
}
