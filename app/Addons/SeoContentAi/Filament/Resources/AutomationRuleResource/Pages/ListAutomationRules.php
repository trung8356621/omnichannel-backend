<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource\Pages;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationAvailabilityGate;
use App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListAutomationRules extends ListRecords
{
    protected static string $resource = AutomationRuleResource::class;

    public function mount(): void
    {
        parent::mount();

        $gate = app(AutomationAvailabilityGate::class);
        if (! $gate->isActionAvailableForManual(AutomationActionCode::WordpressArticleSync->value)) {
            $probe = $gate->resolveManualRules(AutomationActionCode::WordpressArticleSync->value);
            $body = $probe->isEmpty()
                ? __('seo-content-ai::filament.automation.wp_auto_disabled_body')
                : __('seo-content-ai::filament.automation.wp_auto_disabled_body');

            Notification::make()
                ->title(__('seo-content-ai::filament.automation.wp_auto_disabled_title'))
                ->body($body)
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn (): bool => AutomationRuleResource::canCreate()),
        ];
    }
}
