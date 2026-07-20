<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AutomationExecutionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AutomationExecutionResource;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationGraphExecutionService;
use App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewAutomationExecution extends ViewRecord
{
    protected static string $resource = AutomationExecutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancel')
                ->label('Cancel')
                ->color('danger')
                ->visible(fn (): bool => in_array((string) $this->getRecord()->status, ['pending', 'processing', 'scheduled'], true))
                ->action(function (): void {
                    app(AutomationGraphExecutionService::class)->cancelExecution((int) $this->getRecord()->id);
                    Notification::make()->title('Cancellation requested')->success()->send();
                }),
            Actions\Action::make('retry_execution')
                ->label('Retry execution')
                ->visible(fn (): bool => in_array((string) $this->getRecord()->status, ['failed', 'partial'], true))
                ->action(function (): void {
                    $execution = $this->getRecord();
                    if ($execution->rule?->isGraphMode()) {
                        app(AutomationGraphExecutionService::class)->retryExecution((int) $execution->id);
                    } else {
                        app(\App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationExecutionService::class)
                            ->retry((int) $execution->id);
                    }
                    Notification::make()->title('Retry queued')->success()->send();
                }),
            Actions\Action::make('view_rule')
                ->label('View rule')
                ->icon('heroicon-o-bolt')
                ->url(fn (): ?string => $this->getRecord()->rule
                    ? AutomationRuleResource::getUrl('view', ['record' => $this->getRecord()->rule])
                    : null)
                ->visible(fn (): bool => $this->getRecord()->rule !== null),
        ];
    }
}
