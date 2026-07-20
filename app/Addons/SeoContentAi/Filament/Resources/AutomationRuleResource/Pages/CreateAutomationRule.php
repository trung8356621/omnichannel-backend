<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource\Pages;

use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

final class CreateAutomationRule extends CreateRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function handleRecordCreation(array $data): AutomationRule
    {
        return AutomationRuleResource::createRuleFromFormData($data);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Automation rule created')
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return AutomationRuleResource::getUrl('index');
    }
}
