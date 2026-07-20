<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListAutomationRules extends ListRecords
{
    protected static string $resource = AutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn (): bool => AutomationRuleResource::canCreate()),
        ];
    }
}
