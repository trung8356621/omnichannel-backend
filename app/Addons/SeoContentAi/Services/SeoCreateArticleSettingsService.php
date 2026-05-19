<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\WpOption;

final class SeoCreateArticleSettingsService
{
    public const OPTION_KEY = 'seo_create_article_task';

    /**
     * @return array{task_id: ?int}
     */
    public function getSettings(): array
    {
        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return ['task_id' => null];
        }

        $taskId = isset($data['task_id']) ? (int) $data['task_id'] : null;

        return [
            'task_id' => $taskId > 0 ? $taskId : null,
        ];
    }

    public function getTaskId(): ?int
    {
        return $this->getSettings()['task_id'];
    }

    /**
     * @param  array{task_id?: int|null}  $settings
     */
    public function saveSettings(array $settings): void
    {
        $taskId = isset($settings['task_id']) ? (int) $settings['task_id'] : null;

        WpOption::set(self::OPTION_KEY, [
            'task_id' => $taskId > 0 ? $taskId : null,
        ], 'no');
    }
}
