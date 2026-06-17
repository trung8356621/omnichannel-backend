<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Pages\DomainGlobalCtaSettings;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Services\SeoMainDomainService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    public function mount(): void
    {
        parent::mount();

        app(SeoMainDomainService::class)->deduplicatePrimarySitesForVisibleOwners();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('global_cta_settings')
                ->label(__('seo-content-ai::filament.domain.global_cta_settings'))
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(DomainGlobalCtaSettings::getUrl()),
            Actions\CreateAction::make()
                ->label('Add domain')
                ->icon('heroicon-o-plus'),
        ];
    }
}
