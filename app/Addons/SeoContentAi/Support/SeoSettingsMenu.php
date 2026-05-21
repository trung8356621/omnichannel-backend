<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsOverview;
use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsPrompt;
use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsWorkflows;
use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;

final class SeoSettingsMenu
{
    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    public static function items(): array
    {
        return [
            [
                'id' => 'overview',
                'label' => 'Tổng quan',
                'icon' => 'heroicon-o-home',
                'url' => SeoSettingsOverview::getUrl(),
            ],
            [
                'id' => 'workflows',
                'label' => 'Quy trình',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => SeoSettingsWorkflows::getUrl(),
            ],
            [
                'id' => 'ai',
                'label' => 'Cấu hình AI',
                'icon' => 'heroicon-o-cpu-chip',
                'url' => AiConnectionResource::getUrl(),
            ],
            [
                'id' => 'prompt',
                'label' => 'Tùy chỉnh prompt',
                'icon' => 'heroicon-o-chat-bubble-left-ellipsis',
                'url' => SeoSettingsPrompt::getUrl(),
            ],
        ];
    }
}
