<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\WpOption;

final class SeoPromptSettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    public const OPTION_KEY = 'seo_prompt_settings';

    public const KEY_TONE_OF_VOICE = 'tone_of_voice';

    public const KEY_FEATURED_SNIPPET_MIN_ROWS = 'featured_snippet_min_rows';

    public const KEY_FEATURED_SNIPPET_MIN_COLUMNS = 'featured_snippet_min_columns';

    public const KEY_FEATURED_SNIPPET_MAX_COLUMNS = 'featured_snippet_max_columns';

    /** @var list<string> */
    private const DEFAULT_TONES = [
        'Chuyên nghiệp',
        'Thân thiện, gần gũi',
        'Trang trọng, uy tín',
        'Năng động, trẻ trung',
        'Khách quan, trung lập',
        'Thuyết phục, bán hàng',
        'Giáo dục, hướng dẫn',
        'Hài hước nhẹ nhàng',
        'Cao cấp (premium)',
        'Địa phương, thuần Việt',
    ];

    /**
     * @return array{
     *     tone_of_voice: list<string>,
     *     featured_snippet_min_rows: int,
     *     featured_snippet_min_columns: int,
     *     featured_snippet_max_columns: int,
     * }
     */
    /**
     * Dùng trong unit test — không truy vấn wp_options.
     */
    public static function withDefaults(): self
    {
        $service = new self();
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->inMemorySettings;
        }

        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return $this->defaultSettings();
        }

        $tones = $this->normalizeTones($data[self::KEY_TONE_OF_VOICE] ?? null);

        return [
            self::KEY_TONE_OF_VOICE => $tones !== [] ? $tones : self::DEFAULT_TONES,
            self::KEY_FEATURED_SNIPPET_MIN_ROWS => $this->intInRange(
                $data[self::KEY_FEATURED_SNIPPET_MIN_ROWS] ?? null,
                1,
                50,
                10,
            ),
            self::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $this->intInRange(
                $data[self::KEY_FEATURED_SNIPPET_MIN_COLUMNS] ?? null,
                1,
                10,
                2,
            ),
            self::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $this->intInRange(
                $data[self::KEY_FEATURED_SNIPPET_MAX_COLUMNS] ?? null,
                1,
                10,
                5,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function getToneOfVoiceOptions(): array
    {
        return $this->getSettings()[self::KEY_TONE_OF_VOICE];
    }

    /**
     * @return array{min_rows: int, min_columns: int, max_columns: int}
     */
    public function getFeaturedSnippetThresholds(): array
    {
        $settings = $this->getSettings();
        $minCols = $settings[self::KEY_FEATURED_SNIPPET_MIN_COLUMNS];
        $maxCols = max($minCols, $settings[self::KEY_FEATURED_SNIPPET_MAX_COLUMNS]);

        return [
            'min_rows' => $settings[self::KEY_FEATURED_SNIPPET_MIN_ROWS],
            'min_columns' => $minCols,
            'max_columns' => $maxCols,
        ];
    }

    /**
     * Số hàng markdown được đếm (gồm header) tối thiểu để đạt chuẩn Featured Snippet.
     */
    public function featuredSnippetMinMarkdownRowCount(): int
    {
        return $this->getFeaturedSnippetThresholds()['min_rows'] + 1;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $tones = $this->normalizeTones($settings[self::KEY_TONE_OF_VOICE] ?? null);
        $minCols = $this->intInRange(
            $settings[self::KEY_FEATURED_SNIPPET_MIN_COLUMNS] ?? null,
            1,
            10,
            2,
        );
        $maxCols = $this->intInRange(
            $settings[self::KEY_FEATURED_SNIPPET_MAX_COLUMNS] ?? null,
            1,
            10,
            5,
        );

        if ($maxCols < $minCols) {
            $maxCols = $minCols;
        }

        WpOption::set(self::OPTION_KEY, [
            self::KEY_TONE_OF_VOICE => $tones !== [] ? $tones : self::DEFAULT_TONES,
            self::KEY_FEATURED_SNIPPET_MIN_ROWS => $this->intInRange(
                $settings[self::KEY_FEATURED_SNIPPET_MIN_ROWS] ?? null,
                1,
                50,
                10,
            ),
            self::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $minCols,
            self::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $maxCols,
        ], 'no');
    }

    /**
     * @return array{
     *     tone_of_voice: list<string>,
     *     featured_snippet_min_rows: int,
     *     featured_snippet_min_columns: int,
     *     featured_snippet_max_columns: int,
     * }
     */
    private function defaultSettings(): array
    {
        return [
            self::KEY_TONE_OF_VOICE => self::DEFAULT_TONES,
            self::KEY_FEATURED_SNIPPET_MIN_ROWS => 10,
            self::KEY_FEATURED_SNIPPET_MIN_COLUMNS => 2,
            self::KEY_FEATURED_SNIPPET_MAX_COLUMNS => 5,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeTones(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $tones = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $label = trim($item);
            } elseif (is_array($item)) {
                $label = trim((string) ($item['label'] ?? $item['tone'] ?? ''));
            } else {
                continue;
            }

            if ($label === '' || in_array($label, $tones, true)) {
                continue;
            }

            $tones[] = $label;
        }

        return $tones;
    }

    private function intInRange(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $int = (int) $value;

        return max($min, min($max, $int));
    }
}
