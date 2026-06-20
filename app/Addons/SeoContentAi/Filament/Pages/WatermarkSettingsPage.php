<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Models\SeoWatermarkSetting;
use App\Addons\SeoContentAi\Services\SeoWatermarkService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

class WatermarkSettingsPage extends SeoPanelPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark-square';

    protected static ?string $navigationLabel = 'Auto watermark settings';

    protected static ?string $title = 'Automatic image watermark settings';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationParentItem = 'Media library';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'seo-content-ai::filament.pages.watermark-settings-page';

    #[Url]
    public ?int $siteId = null;

    /** Bật = đóng dấu + tối ưu; tắt = chỉ tối ưu ảnh không phải .webp */
    public bool $batchApplyWatermark = true;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        } elseif ($this->siteId === null) {
            $firstSite = $this->resolveSitesQuery()->first();
            $this->siteId = $firstSite instanceof Site ? (int) $firstSite->id : null;
        }

        $this->loadSettings();
    }

    public function updatedSiteId(): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        }

        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        if ($this->siteId === null) {
            $this->form->fill([
                'type' => 'none',
                'auto_watermark' => false,
                'position' => 'bottom-right',
                'opacity' => 0.7,
                'text_content' => 'Image copyright',
            ]);

            return;
        }

        $settings = SeoWatermarkSetting::query()->where('site_id', $this->siteId)->first();

        if ($settings === null) {
            $this->form->fill([
                'type' => 'none',
                'auto_watermark' => false,
                'position' => 'bottom-right',
                'opacity' => 0.7,
                'text_content' => 'Image copyright',
                'text_color' => '#ffffff',
                'text_size' => 20,
                'logo_width_pct' => 20,
            ]);

            return;
        }

        $this->form->fill([
            'type' => $settings->type,
            'auto_watermark' => (bool) $settings->auto_watermark,
            'text_content' => $settings->text_content,
            'text_color' => $settings->text_color,
            'text_size' => $settings->text_size,
            'logo_width_pct' => $settings->logo_width_pct,
            'position' => $settings->position,
            'opacity' => $settings->opacity,
            'logo_path' => $settings->logo_path,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Automatic watermark settings')
                    ->description('Applied when uploading/pasting images to local library. Visual design is managed in "Watermark designer".')
                    ->schema([
                        Toggle::make('auto_watermark')
                            ->label('Automatically watermark on image upload (Upload/Paste)'),

                        Select::make('type')
                            ->label('Default watermark type')
                            ->options([
                                'none' => 'No watermark',
                                'text' => 'Text watermark',
                                'image' => 'Logo watermark',
                            ])
                            ->live()
                            ->required(),

                        TextInput::make('text_content')
                            ->label('Default watermark text content')
                            ->visible(fn ($get) => $get('type') === 'text')
                            ->maxLength(500),

                        FileUpload::make('logo_path')
                            ->label('Watermark logo image file')
                            ->disk('public')
                            ->directory('uploads/watermarks')
                            ->image()
                            ->visible(fn ($get) => $get('type') === 'image'),

                        Select::make('position')
                            ->label('Default position')
                            ->options([
                                'top-left' => 'Top - Left',
                                'top-center' => 'Top - Center',
                                'top-right' => 'Top - Right',
                                'center-left' => 'Center - Left',
                                'center' => 'Center',
                                'center-right' => 'Center - Right',
                                'bottom-left' => 'Bottom - Left',
                                'bottom-center' => 'Bottom - Center',
                                'bottom-right' => 'Bottom - Right',
                            ])
                            ->required(),

                        TextInput::make('opacity')
                            ->label('Default opacity (0.1 - 1.0)')
                            ->numeric()
                            ->minValue(0.1)
                            ->maxValue(1)
                            ->step(0.05)
                            ->default(0.7)
                            ->required(),

                        TextInput::make('text_size')
                            ->label('Text size (px)')
                            ->numeric()
                            ->visible(fn ($get) => $get('type') === 'text')
                            ->default(20),

                        TextInput::make('logo_width_pct')
                            ->label('Logo width (% of image)')
                            ->numeric()
                            ->visible(fn ($get) => $get('type') === 'image')
                            ->default(20),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getForms(): array
    {
        return ['form'];
    }

    public function save(): void
    {
        if ($this->siteId === null) {
            Notification::make()->title('Select website')->warning()->send();

            return;
        }

        $formData = $this->form->getState();

        $existing = SeoWatermarkSetting::query()->where('site_id', $this->siteId)->first();
        $logoPath = $existing?->logo_path;

        if (! empty($formData['logo_path'])) {
            $uploaded = is_array($formData['logo_path']) ? ($formData['logo_path'][0] ?? null) : $formData['logo_path'];
            if (is_string($uploaded) && $uploaded !== '') {
                if (filled($logoPath) && $logoPath !== $uploaded) {
                    Storage::disk('public')->delete((string) $logoPath);
                }
                $logoPath = $uploaded;
            }
        }

        $type = (string) ($formData['type'] ?? 'none');
        if ($type === 'none' && $existing instanceof SeoWatermarkSetting && $existing->isConfiguredForApply()) {
            $type = $existing->type !== SeoWatermarkSetting::TYPE_NONE
                ? (string) $existing->type
                : SeoWatermarkSetting::TYPE_TEXT;
        }

        SeoWatermarkSetting::query()->updateOrCreate(
            ['site_id' => $this->siteId],
            [
                'type' => $type,
                'auto_watermark' => (bool) ($formData['auto_watermark'] ?? false),
                'text_content' => $formData['text_content'] ?? null,
                'text_color' => (string) ($formData['text_color'] ?? '#ffffff'),
                'text_size' => (int) ($formData['text_size'] ?? 20),
                'logo_width_pct' => (int) ($formData['logo_width_pct'] ?? 20),
                'position' => (string) ($formData['position'] ?? 'bottom-right'),
                'opacity' => (float) ($formData['opacity'] ?? 0.7),
                'logo_path' => $logoPath,
            ],
        );

        Notification::make()
            ->title('Automatic watermark settings saved successfully')
            ->success()
            ->send();
    }

    public function applyBatchToCurrentSite(): void
    {
        if ($this->siteId === null) {
            Notification::make()->title('Select domain')->warning()->send();

            return;
        }

        @set_time_limit(600);

        $result = app(SeoWatermarkService::class)->applyBatchAllForSite(
            (int) $this->siteId,
            $this->batchApplyWatermark,
        );

        $processed = (int) ($result['local_watermark'] ?? 0)
            + (int) ($result['local_optimize'] ?? 0)
            + (int) ($result['wp_watermark'] ?? 0)
            + (int) ($result['wp_optimize'] ?? 0);

        if (filled($result['message'] ?? null) && $processed === 0) {
            Notification::make()
                ->title((string) $result['message'])
                ->warning()
                ->send();

            return;
        }

        $modeLabel = $this->batchApplyWatermark
            ? 'Watermark + optimize (WebP)'
            : 'Optimize only (skip .webp files)';

        $body = $modeLabel."\n";
        $body .= sprintf(
            'Local - watermarked: %d · optimized: %d · skipped: %d.',
            (int) ($result['local_watermark'] ?? 0),
            (int) ($result['local_optimize'] ?? 0),
            (int) ($result['local_skipped'] ?? 0),
        );
        $body .= "\n".sprintf(
            'WordPress - watermarked: %d · optimized: %d · skipped: %d.',
            (int) ($result['wp_watermark'] ?? 0),
            (int) ($result['wp_optimize'] ?? 0),
            (int) ($result['wp_skipped'] ?? 0),
        );

        if ((int) ($result['wp_errors'] ?? 0) > 0) {
            $body .= "\nWP errors: ".(int) $result['wp_errors'].'.';
        }

        if ($this->batchApplyWatermark) {
            $body .= "\nOriginal WordPress images are backed up on Laravel (first run).";
        }

        Notification::make()
            ->title('Batch processing completed')
            ->body($body)
            ->success()
            ->duration(15000)
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_designer')
                ->label('Open visual designer')
                ->icon('heroicon-o-paint-brush')
                ->url(fn (): string => WatermarkEditor::getUrl([
                    'siteId' => $this->siteId,
                ])),
            Action::make('batch_watermark')
                ->label('Apply to all images')
                ->icon('heroicon-o-photo')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Apply to all images')
                ->modalDescription(
                    'Optimize images (resize, WebP based on "Image optimization settings"). '
                    .'If watermark is enabled: apply watermark before optimization. '
                    .'Optimize-only mode skips files already in .webp format. '
                    .'WordPress images are backed up on Laravel when edited.'
                )
                ->action('applyBatchToCurrentSite')
                ->visible(fn (): bool => $this->siteId !== null),
        ];
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSitesProperty(): Collection
    {
        return $this->resolveSitesQuery()->get();
    }

    public function hasLockedGlobalSite(): bool
    {
        return SeoAccessControl::hasGlobalSiteScope();
    }

    public function currentSiteDomain(): ?string
    {
        if ($this->siteId === null || $this->siteId <= 0) {
            return null;
        }

        $site = $this->sites->firstWhere('id', (int) $this->siteId);

        return $site instanceof Site ? (string) $site->domain : null;
    }

    private function resolveSitesQuery()
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query;
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.auto_watermark_settings');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.media_library');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.auto_watermark_settings_title');
    }
}
