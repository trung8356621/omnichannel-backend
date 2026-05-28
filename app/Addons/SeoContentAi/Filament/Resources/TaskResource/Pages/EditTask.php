<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\TaskResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open_builder')
                ->label('Open workflow builder')
                ->icon('heroicon-o-squares-2x2')
                ->color('info')
                ->url(fn (): string => TaskResource::getUrl('builder', ['record' => $this->record])),
            Actions\DeleteAction::make(),
        ];
    }
}
