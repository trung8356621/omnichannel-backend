<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class EditDomainInfo extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.edit-domain-info';

    protected static ?string $title = 'Technical SEO';

    /** @var array{short_description: string, cta: list<array{type: string, value: string}>, links: list<array{keyword: string, link: string}>} */
    public array $promptContextData = [
        'short_description' => '',
        'cta' => [],
        'links' => [],
    ];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);

        $this->promptContextData = app(SiteDomainPromptContextService::class)
            ->getForSite($this->getSite());

        $this->form->fill($this->promptContextData);
    }

    public function form(Form $form): Form
    {
        $maxWords = SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS;

        return $form
            ->schema([
                Forms\Components\Section::make('Short description')
                    ->description("Maximum {$maxWords} words - used in article prompts ({{site_short_description}}).")
                    ->schema([
                        Forms\Components\Textarea::make('short_description')
                            ->label('Website / brand short description')
                            ->rows(6)
                            ->maxLength(8000)
                            ->live(debounce: 400)
                            ->helperText(function (Get $get) use ($maxWords): string {
                                $count = app(SiteDomainPromptContextService::class)
                                    ->countWords((string) $get('short_description'));

                                return "Entered: {$count} / {$maxWords} words.";
                            }),
                    ]),
                Forms\Components\Section::make('CTA / Contact')
                    ->description('List of contact information used in prompts ({{site_cta}}). Click "Add item" to insert more.')
                    ->schema([
                        Forms\Components\Repeater::make('cta')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('type')
                                    ->label('Type')
                                    ->placeholder('e.g. phone, email, address, zalo...')
                                    ->required()
                                    ->maxLength(64)
                                    ->columnSpan(4),
                                Forms\Components\TextInput::make('value')
                                    ->label('Value')
                                    ->required()
                                    ->maxLength(500)
                                    ->columnSpan(6),
                            ])
                            ->columns(10)
                            ->defaultItems(0)
                            ->addActionLabel('Add contact item')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => filled($state['type'] ?? null)
                                ? (string) $state['type']
                                : 'New item'),
                    ]),
                Forms\Components\Section::make('Link list')
                    ->description('Keywords mapped to internal/landing URLs for prompts ({{site_links}}).')
                    ->schema([
                        Forms\Components\Repeater::make('links')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('keyword')
                                    ->label('Keyword')
                                    ->placeholder('e.g. pricing, contact...')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(4),
                                Forms\Components\TextInput::make('link')
                                    ->label('Link')
                                    ->placeholder('https://... or /path')
                                    ->required()
                                    ->maxLength(2000)
                                    ->columnSpan(6),
                            ])
                            ->columns(10)
                            ->defaultItems(0)
                            ->addActionLabel('Add link')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => filled($state['keyword'] ?? null)
                                ? (string) $state['keyword']
                                : 'New link'),
                    ]),
            ])
            ->statePath('promptContextData');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Technical SEO: ' . $this->getSite()->domain;
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('back')
                ->label('Domain list')
                ->icon('heroicon-o-arrow-left')
                ->url(DomainResource::getUrl('index')),
        ];
    }

    public function saveDomainPromptContext(SiteDomainPromptContextService $service): void
    {
        $data = $this->form->getState();

        try {
            $service->saveForSite($this->getSite(), $data);

            Notification::make()
                ->title('Domain information saved')
                ->success()
                ->send();
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Unable to save')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private function getSite(): Site
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return $site;
    }
}
