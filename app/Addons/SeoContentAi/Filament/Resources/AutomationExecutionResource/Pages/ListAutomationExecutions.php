<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AutomationExecutionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AutomationExecutionResource;
use Filament\Resources\Pages\ListRecords;

final class ListAutomationExecutions extends ListRecords
{
    protected static string $resource = AutomationExecutionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
