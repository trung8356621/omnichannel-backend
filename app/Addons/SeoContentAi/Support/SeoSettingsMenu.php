<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Filament\Pages\ManagePluginRelease;
use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsEditor;
use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsKeywords;
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
                'label' => 'Overview',
                'icon' => 'heroicon-o-home',
                'url' => SeoSettingsOverview::getUrl(),
            ],
            [
                'id' => 'workflows',
                'label' => 'Workflows',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => SeoSettingsWorkflows::getUrl(),
            ],
            [
                'id' => 'editor',
                'label' => 'Article editor',
                'icon' => 'heroicon-o-document-text',
                'url' => SeoSettingsEditor::getUrl(),
            ],
            [
                'id' => 'keywords',
                'label' => 'Keywords',
                'icon' => 'heroicon-o-key',
                'url' => SeoSettingsKeywords::getUrl(),
            ],
            [
                'id' => 'ai',
                'label' => 'AI settings',
                'icon' => 'heroicon-o-cpu-chip',
                'url' => AiConnectionResource::getUrl(),
            ],
            [
                'id' => 'prompt',
                'label' => 'Prompt settings',
                'icon' => 'heroicon-o-chat-bubble-left-ellipsis',
                'url' => SeoSettingsPrompt::getUrl(),
            ],
            [
                'id' => 'wp-plugin-release',
                'label' => 'WP Plugin release',
                'icon' => 'heroicon-o-arrow-up-tray',
                'url' => ManagePluginRelease::getUrl(),
            ],
        ];
    }
}
