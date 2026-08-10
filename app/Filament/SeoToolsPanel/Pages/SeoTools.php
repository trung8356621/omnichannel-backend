<?php

declare(strict_types=1);

namespace App\Filament\SeoToolsPanel\Pages;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Services\SeoToolsService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;
use Omnichannel\Addons\Seo\Support\SeoToolsAccessControl;
use App\Models\SeoDatabaseConnection;
use App\Models\User;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Get;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class SeoTools extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'seo-content-ai::filament.pages.seo-tools';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = '';

    public static function getSlug(): string
    {
        return '';
    }

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SitePolylangService $polylang): void
    {
        $this->bootstrapSeoDatabaseForTranslate();

        $options = $this->languageOptions($polylang);
        $defaultLang = array_key_first($options) ?? 'en';

        $this->form->fill([
            'mdToHtml' => ['input' => '', 'output' => ''],
            'htmlToMd' => ['input' => '', 'output' => ''],
            'mdToFaq' => ['input' => '', 'faqs' => []],
            'translate' => ['input' => '', 'language' => $defaultLang, 'output' => ''],
        ]);
    }

    public function form(Form $form): Form
    {
        $tabs = [
            Tab::make('md_to_html')
                ->label(__('seo-content-ai::filament.tools.tab_md_to_html'))
                ->schema([
                    Textarea::make('mdToHtml.input')
                        ->label(__('seo-content-ai::filament.tools.input_markdown'))
                        ->rows(12)
                        ->columnSpanFull(),
                    Actions::make([
                        Action::make('runMdToHtml')
                            ->label(__('seo-content-ai::filament.tools.run_md_to_html'))
                            ->icon('heroicon-o-arrow-right')
                            ->action('runMdToHtml'),
                    ]),
                    Textarea::make('mdToHtml.output')
                        ->label(__('seo-content-ai::filament.tools.output_html'))
                        ->rows(12)
                        ->readOnly()
                        ->columnSpanFull(),
                ]),
            Tab::make('html_to_md')
                ->label(__('seo-content-ai::filament.tools.tab_html_to_md'))
                ->schema([
                    Textarea::make('htmlToMd.input')
                        ->label(__('seo-content-ai::filament.tools.input_html'))
                        ->rows(12)
                        ->columnSpanFull(),
                    Actions::make([
                        Action::make('runHtmlToMd')
                            ->label(__('seo-content-ai::filament.tools.run_html_to_md'))
                            ->icon('heroicon-o-arrow-left')
                            ->color('gray')
                            ->action('runHtmlToMd'),
                    ]),
                    Textarea::make('htmlToMd.output')
                        ->label(__('seo-content-ai::filament.tools.output_markdown'))
                        ->rows(12)
                        ->readOnly()
                        ->columnSpanFull(),
                ]),
            Tab::make('md_to_faq')
                ->label(__('seo-content-ai::filament.tools.tab_md_to_faq'))
                ->schema([
                    Textarea::make('mdToFaq.input')
                        ->label(__('seo-content-ai::filament.tools.input_markdown'))
                        ->rows(12)
                        ->columnSpanFull(),
                    Actions::make([
                        Action::make('runMdToFaq')
                            ->label(__('seo-content-ai::filament.tools.run_md_to_faq'))
                            ->icon('heroicon-o-question-mark-circle')
                            ->color('gray')
                            ->action('runMdToFaq'),
                    ]),
                    ViewField::make('mdToFaq_results')
                        ->view('seo-content-ai::filament.pages.partials.seo-tools-faq-results')
                        ->viewData(fn (Get $get): array => [
                            'faqs' => is_array($get('mdToFaq.faqs')) ? $get('mdToFaq.faqs') : [],
                        ])
                        ->columnSpanFull(),
                ]),
        ];

        if (self::canUseTranslateTab()) {
            $tabs[] = Tab::make('translate')
                ->label(__('seo-content-ai::filament.tools.tab_translate'))
                ->schema([
                    Textarea::make('translate.input')
                        ->label(__('seo-content-ai::filament.tools.input_text'))
                        ->rows(10)
                        ->columnSpanFull(),
                    \Filament\Forms\Components\Select::make('translate.language')
                        ->label(__('seo-content-ai::filament.tools.target_language'))
                        ->options(fn (): array => $this->languageOptions(app(SitePolylangService::class)))
                        ->required(),
                    Actions::make([
                        Action::make('runTranslate')
                            ->label(__('seo-content-ai::filament.tools.run_translate'))
                            ->icon('heroicon-o-language')
                            ->color('info')
                            ->action('runTranslate'),
                    ]),
                    Textarea::make('translate.output')
                        ->label(__('seo-content-ai::filament.tools.output_markdown'))
                        ->rows(10)
                        ->readOnly()
                        ->columnSpanFull(),
                ]);
        }

        return $form
            ->schema([
                Tabs::make('tools')
                    ->tabs($tabs)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    protected function getForms(): array
    {
        return ['form'];
    }

    public function runMdToHtml(SeoToolsService $tools): void
    {
        $state = $this->form->getState();
        $input = (string) ($state['mdToHtml']['input'] ?? '');
        $state['mdToHtml']['output'] = $tools->markdownToHtml($input);
        $this->form->fill($state);
    }

    public function runHtmlToMd(SeoToolsService $tools): void
    {
        $state = $this->form->getState();
        $input = (string) ($state['htmlToMd']['input'] ?? '');
        $state['htmlToMd']['output'] = $tools->htmlToMarkdown($input);
        $this->form->fill($state);
    }

    public function runMdToFaq(SeoToolsService $tools): void
    {
        $state = $this->form->getState();
        $input = (string) ($state['mdToFaq']['input'] ?? '');
        $state['mdToFaq']['faqs'] = $tools->markdownToFaq($input);
        $this->form->fill($state);
    }

    public function runTranslate(SeoToolsService $tools): void
    {
        abort_unless(self::canUseTranslateTab(), 403);

        $this->bootstrapSeoDatabaseForTranslate();

        $state = $this->form->getState();
        $input = (string) ($state['translate']['input'] ?? '');
        $language = (string) ($state['translate']['language'] ?? '');

        try {
            $state['translate']['output'] = $tools->translateText($input, $language);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.tools.translate_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->form->fill($state);

        Notification::make()
            ->title(__('seo-content-ai::filament.tools.translate_success'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public static function canUseTranslateTab(): bool
    {
        return SeoToolsAccessControl::canUseTranslateTool();
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.tools.title');
    }

    /**
     * @return array<string, string>
     */
    private function languageOptions(SitePolylangService $polylang): array
    {
        return $polylang->defaultLanguageOptions();
    }

    private function bootstrapSeoDatabaseForTranslate(): void
    {
        if (! self::canUseTranslateTab()) {
            return;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $ownerId = SeoToolsAccessControl::apiConnectionOwnerId($user);
        if ($ownerId === null || $ownerId <= 0) {
            return;
        }

        $connection = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->whereHas('users', fn (Builder $query): Builder => $query->where('users.id', $ownerId))
            ->orderBy('id')
            ->first();

        $service = app(SeoDatabaseConnectionService::class);
        if ($connection instanceof SeoDatabaseConnection) {
            $service->bootstrapFromConnection($connection);

            return;
        }

        $service->bootstrapLegacySharedConnection();
    }
}
