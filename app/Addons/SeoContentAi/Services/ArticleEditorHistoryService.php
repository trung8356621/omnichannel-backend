<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\WpOption;

class ArticleEditorHistoryService
{
    public const OPTION_KEY = 'seo_article_editor_settings';

    public const DEFAULT_HISTORY_STEP = 20;

    public const DEFAULT_AUTOSAVE_INTERVAL_SECONDS = 60;

    /**
     * @return array{history_step: int, autosave_interval_seconds: int}
     */
    public function getSettings(): array
    {
        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return [
                'history_step' => self::DEFAULT_HISTORY_STEP,
                'autosave_interval_seconds' => self::DEFAULT_AUTOSAVE_INTERVAL_SECONDS,
            ];
        }

        $steps = (int) ($data['history_step'] ?? self::DEFAULT_HISTORY_STEP);
        $autosave = (int) ($data['autosave_interval_seconds'] ?? self::DEFAULT_AUTOSAVE_INTERVAL_SECONDS);

        return [
            'history_step' => max(1, min(100, $steps)),
            'autosave_interval_seconds' => max(0, min(600, $autosave)),
        ];
    }

    public function getHistoryStep(): int
    {
        return $this->getSettings()['history_step'];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $current = $this->getSettings();
        $steps = (int) ($settings['history_step'] ?? $current['history_step']);
        $autosave = (int) ($settings['autosave_interval_seconds'] ?? $current['autosave_interval_seconds']);

        WpOption::set(self::OPTION_KEY, [
            'history_step' => max(1, min(100, $steps)),
            'autosave_interval_seconds' => max(0, min(600, $autosave)),
        ], 'no');
    }
}
