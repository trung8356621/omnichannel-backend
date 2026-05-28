<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsPrompt extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/prompt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Prompt settings';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-prompt';

    /** @var array<string, mixed> */
    public array $promptSettingsData = [];

    public function mount(SeoPromptSettingsService $settings): void
    {
        $raw = $settings->getSettings();

        $this->promptSettingsData = [
            SeoPromptSettingsService::KEY_TONE_OF_VOICE => array_map(
                static fn (string $tone): array => ['label' => $tone],
                $raw[SeoPromptSettingsService::KEY_TONE_OF_VOICE],
            ),
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_ROWS => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_ROWS],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS],
        ];

        $this->form->fill($this->promptSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_prompt.tone_section'))
                    ->description(__('seo-content-ai::filament.settings_prompt.tone_section_description'))
                    ->schema([
                        Forms\Components\Repeater::make(SeoPromptSettingsService::KEY_TONE_OF_VOICE)
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label(__('seo-content-ai::filament.settings_prompt.tone_label'))
                                    ->required()
                                    ->maxLength(120)
                                    ->columnSpanFull(),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel(__('seo-content-ai::filament.settings_prompt.add_tone'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                ? (string) $state['label']
                                : __('seo-content-ai::filament.settings_prompt.new_tone')),
                    ]),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_prompt.featured_snippet'))
                    ->description(__('seo-content-ai::filament.settings_prompt.featured_snippet_description'))
                    ->schema([
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_ROWS)
                            ->label(__('seo-content-ai::filament.settings_prompt.min_rows'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->required()
                            ->default(10),
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS)
                            ->label(__('seo-content-ai::filament.settings_prompt.min_columns'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required()
                            ->default(2),
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS)
                            ->label(__('seo-content-ai::filament.settings_prompt.max_columns'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required()
                            ->default(5),
                    ])
                    ->columns(3),
            ])
            ->statePath('promptSettingsData');
    }

    public function savePromptSettings(SeoPromptSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->saveSettings([
            SeoPromptSettingsService::KEY_TONE_OF_VOICE => $data[SeoPromptSettingsService::KEY_TONE_OF_VOICE] ?? [],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_ROWS => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_ROWS] ?? 10,
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS] ?? 2,
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS] ?? 5,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_prompt.saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
