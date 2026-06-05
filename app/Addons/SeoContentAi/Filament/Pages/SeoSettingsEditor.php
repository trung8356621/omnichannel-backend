<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Services\ArticleEditorHistoryService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/editor';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Article editor';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-editor';

    /** @var array<string, mixed> */
    public array $editorSettingsData = [];

    public function mount(ArticleEditorHistoryService $settings): void
    {
        $this->editorSettingsData = $settings->getSettings();
        $this->form->fill($this->editorSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_editor.section'))
                    ->description(__('seo-content-ai::filament.settings_editor.section_description'))
                    ->schema([
                        Forms\Components\TextInput::make('history_step')
                            ->label(__('seo-content-ai::filament.settings_editor.history_step'))
                            ->helperText(__('seo-content-ai::filament.settings_editor.history_step_hint'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required()
                            ->default(ArticleEditorHistoryService::DEFAULT_HISTORY_STEP),
                        Forms\Components\TextInput::make('autosave_interval_seconds')
                            ->label(__('seo-content-ai::filament.settings_editor.autosave_interval'))
                            ->helperText(__('seo-content-ai::filament.settings_editor.autosave_interval_hint'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(600)
                            ->required()
                            ->default(ArticleEditorHistoryService::DEFAULT_AUTOSAVE_INTERVAL_SECONDS)
                            ->suffix(__('seo-content-ai::filament.settings_editor.seconds_suffix')),
                    ])
                    ->columns(2),
            ])
            ->statePath('editorSettingsData');
    }

    public function saveEditorSettings(ArticleEditorHistoryService $settings): void
    {
        $data = $this->form->getState();
        $settings->saveSettings($data);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_editor.saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
