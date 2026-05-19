<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\WpOption;

class ArticleEditorHistoryService
{
    public const OPTION_KEY = 'seo_article_editor_settings';

    public const DEFAULT_HISTORY_STEP = 20;

    /**
     * @return array{history_step: int}
     */
    public function getSettings(): array
    {
        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return ['history_step' => self::DEFAULT_HISTORY_STEP];
        }

        $steps = (int) ($data['history_step'] ?? self::DEFAULT_HISTORY_STEP);

        return [
            'history_step' => max(1, min(100, $steps)),
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

        WpOption::set(self::OPTION_KEY, [
            'history_step' => max(1, min(100, $steps)),
        ], 'no');
    }
}
