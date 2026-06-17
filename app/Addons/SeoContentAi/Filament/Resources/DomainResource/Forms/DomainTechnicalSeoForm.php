<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Forms;

use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use Filament\Forms;
use Filament\Forms\Get;

final class DomainTechnicalSeoForm
{
    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function schema(): array
    {
        $maxWords = SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS;

        return [
            Forms\Components\Group::make()
                ->statePath('promptContext')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\Group::make()
                                ->schema([
                                    self::domainSettingsSection(),
                                    self::ctaSection(),
                                ]),
                            Forms\Components\Group::make()
                                ->schema([
                                    self::shortDescriptionSection($maxWords),
                                    self::linkListSection(),
                                ]),
                        ]),
                ]),
        ];
    }

    private static function domainSettingsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.domain_settings'))
            ->description(__('seo-content-ai::filament.domain.domain_settings_description'))
            ->schema([
                Forms\Components\Select::make('tone')
                    ->label(__('seo-content-ai::filament.domain.domain_tone'))
                    ->helperText(__('seo-content-ai::filament.domain.domain_tone_hint'))
                    ->options(fn (Get $get): array => app(SeoPromptSettingsService::class)
                        ->toneOfVoiceSelectOptions((string) $get('tone')))
                    ->searchable()
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.domain.domain_tone_placeholder'))
                    ->columnSpanFull(),
            ])
            ->collapsible();
    }

    private static function shortDescriptionSection(int $maxWords): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.short_description'))
            ->description(__('seo-content-ai::filament.domain.short_description_hint', ['max' => $maxWords]))
            ->schema([
                Forms\Components\Textarea::make('short_description')
                    ->label(__('seo-content-ai::filament.domain.short_description_label'))
                    ->rows(6)
                    ->maxLength(8000)
                    ->live(debounce: 400)
                    ->helperText(function (Get $get) use ($maxWords): string {
                        $count = app(SiteDomainPromptContextService::class)
                            ->countWords((string) $get('short_description'));

                        return __('seo-content-ai::filament.domain.short_description_words', [
                            'count' => $count,
                            'max' => $maxWords,
                        ]);
                    }),
            ])
            ->collapsible();
    }

    private static function ctaSection(): Forms\Components\Section
    {
        $phoneFields = [];
        foreach (SiteDomainPromptContextService::phoneSlotFormLabels() as $slot => $label) {
            $phoneFields[] = Forms\Components\TextInput::make($slot)
                ->label(__("seo-content-ai::filament.domain.{$slot}"))
                ->helperText(__('seo-content-ai::filament.domain.phone_slot_hint', ['slot' => $slot]))
                ->tel()
                ->maxLength(50);
        }

        $emailFields = [];
        foreach (SiteDomainPromptContextService::emailSlotFormLabels() as $slot => $label) {
            $emailFields[] = Forms\Components\TextInput::make($slot)
                ->label(__("seo-content-ai::filament.domain.{$slot}"))
                ->helperText(__('seo-content-ai::filament.domain.email_slot_hint', ['slot' => $slot]))
                ->email()
                ->maxLength(255);
        }

        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.cta_section'))
            ->description(__('seo-content-ai::filament.domain.cta_section_hint'))
            ->schema([
                Forms\Components\Placeholder::make('website_auto')
                    ->label('[website]')
                    ->content(__('seo-content-ai::filament.domain.website_auto_hint')),
                Forms\Components\Grid::make(3)->schema($phoneFields),
                Forms\Components\Grid::make(3)->schema($emailFields),
                Forms\Components\Textarea::make('cta_intro')
                    ->label(__('seo-content-ai::filament.domain.cta_intro'))
                    ->helperText(__('seo-content-ai::filament.domain.cta_intro_hint'))
                    ->rows(4)
                    ->maxLength(4000)
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('cta')
                    ->label('')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label(__('seo-content-ai::filament.domain.cta_type'))
                            ->options(SiteDomainPromptContextService::ctaFormTypeOptions())
                            ->required()
                            ->native(false)
                            ->columnSpan(4),
                        Forms\Components\TextInput::make('value')
                            ->label(__('seo-content-ai::filament.domain.cta_value'))
                            ->required()
                            ->maxLength(500)
                            ->columnSpan(6),
                    ])
                    ->columns(10)
                    ->defaultItems(0)
                    ->addActionLabel(__('seo-content-ai::filament.domain.cta_add'))
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => filled($state['type'] ?? null)
                        ? (string) $state['type']
                        : __('seo-content-ai::filament.domain.cta_new')),
            ])
            ->collapsible();
    }

    private static function linkListSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.link_list'))
            ->description(__('seo-content-ai::filament.domain.link_list_hint'))
            ->schema([
                Forms\Components\Repeater::make('links')
                    ->label('')
                    ->schema([
                        Forms\Components\TextInput::make('keyword')
                            ->label(__('seo-content-ai::filament.domain.link_keyword'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(4),
                        Forms\Components\TextInput::make('link')
                            ->label(__('seo-content-ai::filament.domain.link_url'))
                            ->placeholder('https://...')
                            ->required()
                            ->maxLength(2000)
                            ->columnSpan(6),
                    ])
                    ->columns(10)
                    ->defaultItems(0)
                    ->addActionLabel(__('seo-content-ai::filament.domain.link_add'))
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => filled($state['keyword'] ?? null)
                        ? (string) $state['keyword']
                        : __('seo-content-ai::filament.domain.link_new')),
            ])
            ->collapsible();
    }
}
