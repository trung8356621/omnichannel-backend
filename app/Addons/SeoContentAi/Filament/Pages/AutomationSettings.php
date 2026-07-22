<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationSettingsService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;

final class AutomationSettings extends SeoPanelPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'automation/settings';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Automation';

    protected static ?int $navigationSort = 95;

    protected static ?string $navigationLabel = 'Automation Settings';

    protected static ?string $title = 'Automation Settings';

    protected static string $view = 'seo-content-ai::filament.pages.automation-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(AutomationSettingsService $settings): void
    {
        abort_unless(SeoAccessControl::canManageAutomationSettings(), 403);
        $this->form->fill($settings->getSettings());
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageAutomationSettings();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.automation.execution_log_retention'))
                    ->description(__('seo-content-ai::filament.automation.execution_log_retention_help'))
                    ->schema([
                        Forms\Components\Select::make(AutomationSettingsService::KEY_EXECUTION_LOG_RETENTION)
                            ->label(__('seo-content-ai::filament.automation.execution_log_retention'))
                            ->options(AutomationSettingsService::retentionOptions())
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make(AutomationSettingsService::KEY_CUSTOM_RETENTION_DAYS)
                            ->label(__('seo-content-ai::filament.automation.custom_retention_days'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->visible(fn (Forms\Get $get): bool => $get(AutomationSettingsService::KEY_EXECUTION_LOG_RETENTION) === AutomationSettingsService::RETENTION_CUSTOM)
                            ->required(fn (Forms\Get $get): bool => $get(AutomationSettingsService::KEY_EXECUTION_LOG_RETENTION) === AutomationSettingsService::RETENTION_CUSTOM),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(AutomationSettingsService $settings): void
    {
        SeoAccessControl::guardAutomationClearLogs();

        $settings->saveSettings($this->form->getState());

        Notification::make()
            ->title(__('seo-content-ai::filament.automation.settings_saved'))
            ->success()
            ->send();
    }
}
