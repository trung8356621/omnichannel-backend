<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages\Concerns;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;

trait HasKeywordWorkspaceNavigation
{
    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    public function getKeywordWorkspaceNavItems(): array
    {
        return [
            [
                'key' => 'index',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_dictionary'),
                'url' => KeywordResource::getUrl('index'),
            ],
            [
                'key' => 'anchor-audit',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_anchor_audit'),
                'url' => KeywordResource::getUrl('anchor-audit'),
            ],
            [
                'key' => 'workspace-2',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_two'),
                'url' => KeywordResource::getUrl('workspace-2'),
            ],
            [
                'key' => 'workspace-3',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_three'),
                'url' => KeywordResource::getUrl('workspace-3'),
            ],
            [
                'key' => 'workspace-4',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_four'),
                'url' => KeywordResource::getUrl('workspace-4'),
            ],
        ];
    }

    abstract protected function getActiveKeywordWorkspaceKey(): string;
}
