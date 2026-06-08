<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListKeywords extends ListRecords
{
    protected static string $resource = KeywordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add keyword')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'free' => Tab::make('Free')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('parent_id')
                    ->whereDoesntHave('children')),
            'pillar_cluster' => Tab::make('Pillar / Cluster')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('parent_id')
                    ->whereHas('children')),
        ];
    }
}
