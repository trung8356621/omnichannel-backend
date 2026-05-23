<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Models\SeoWatermarkSetting;
use App\Addons\SeoContentAi\Services\SeoWatermarkService;
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
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

class WatermarkSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark-square';

    protected static ?string $navigationLabel = 'Cấu hình tự động';

    protected static ?string $title = 'Cài đặt tự động đóng dấu ảnh';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationParentItem = 'Thư viện hình ảnh';

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
        if ($this->siteId === null) {
            $firstSite = $this->resolveSitesQuery()->first();
            $this->siteId = $firstSite instanceof Site ? (int) $firstSite->id : null;
        }

        $this->loadSettings();
    }

    public function updatedSiteId(): void
    {
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
                'text_content' => 'Bản quyền hình ảnh',
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
                'text_content' => 'Bản quyền hình ảnh',
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
                Section::make('Cài đặt tự động hóa đóng dấu bản quyền')
                    ->description('Áp dụng khi upload/dán ảnh vào thư viện nội bộ. Thiết kế trực quan mở tại «Thiết kế đóng dấu».')
                    ->schema([
                        Toggle::make('auto_watermark')
                            ->label('Tự động đóng dấu khi tải ảnh lên (Upload/Paste)'),

                        Select::make('type')
                            ->label('Loại đóng dấu mặc định')
                            ->options([
                                'none' => 'Không đóng dấu',
                                'text' => 'Sử dụng chữ (Text)',
                                'image' => 'Sử dụng Logo (Image)',
                            ])
                            ->live()
                            ->required(),

                        TextInput::make('text_content')
                            ->label('Nội dung chữ đóng dấu mặc định')
                            ->visible(fn ($get) => $get('type') === 'text')
                            ->maxLength(500),

                        FileUpload::make('logo_path')
                            ->label('File ảnh Logo bản quyền')
                            ->disk('public')
                            ->directory('uploads/watermarks')
                            ->image()
                            ->visible(fn ($get) => $get('type') === 'image'),

                        Select::make('position')
                            ->label('Vị trí mặc định')
                            ->options([
                                'top-left' => 'Góc trên — Trái',
                                'top-center' => 'Góc trên — Giữa',
                                'top-right' => 'Góc trên — Phải',
                                'center-left' => 'Giữa — Trái',
                                'center' => 'Chính giữa ảnh',
                                'center-right' => 'Giữa — Phải',
                                'bottom-left' => 'Góc dưới — Trái',
                                'bottom-center' => 'Góc dưới — Giữa',
                                'bottom-right' => 'Góc dưới — Phải',
                            ])
                            ->required(),

                        TextInput::make('opacity')
                            ->label('Độ mờ mặc định (0.1 — 1.0)')
                            ->numeric()
                            ->minValue(0.1)
                            ->maxValue(1)
                            ->step(0.05)
                            ->default(0.7)
                            ->required(),

                        TextInput::make('text_size')
                            ->label('Cỡ chữ (px)')
                            ->numeric()
                            ->visible(fn ($get) => $get('type') === 'text')
                            ->default(20),

                        TextInput::make('logo_width_pct')
                            ->label('Chiều rộng logo (% ảnh)')
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
            Notification::make()->title('Chọn website')->warning()->send();

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

        SeoWatermarkSetting::query()->updateOrCreate(
            ['site_id' => $this->siteId],
            [
                'type' => (string) ($formData['type'] ?? 'none'),
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
            ->title('Đã lưu cấu hình tự động đóng dấu thành công!')
            ->success()
            ->send();
    }

    public function applyBatchToCurrentSite(): void
    {
        if ($this->siteId === null) {
            Notification::make()->title('Chọn tên miền')->warning()->send();

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
            ? 'Đóng dấu + tối ưu (WebP)'
            : 'Chỉ tối ưu (bỏ qua file .webp)';

        $body = $modeLabel . "\n";
        $body .= sprintf(
            'Nội bộ — đóng dấu: %d · tối ưu: %d · bỏ qua: %d.',
            (int) ($result['local_watermark'] ?? 0),
            (int) ($result['local_optimize'] ?? 0),
            (int) ($result['local_skipped'] ?? 0),
        );
        $body .= "\n" . sprintf(
            'WordPress — đóng dấu: %d · tối ưu: %d · bỏ qua: %d.',
            (int) ($result['wp_watermark'] ?? 0),
            (int) ($result['wp_optimize'] ?? 0),
            (int) ($result['wp_skipped'] ?? 0),
        );

        if ((int) ($result['wp_errors'] ?? 0) > 0) {
            $body .= "\nLỗi WP: " . (int) $result['wp_errors'] . '.';
        }

        if ($this->batchApplyWatermark) {
            $body .= "\nẢnh gốc WP được backup trên Laravel (lần đầu).";
        }

        Notification::make()
            ->title('Đã xử lý hàng loạt')
            ->body($body)
            ->success()
            ->duration(15000)
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_designer')
                ->label('Mở thiết kế trực quan')
                ->icon('heroicon-o-paint-brush')
                ->url(fn (): string => WatermarkEditor::getUrl([
                    'siteId' => $this->siteId,
                ])),
            Action::make('batch_watermark')
                ->label('Áp dụng toàn bộ ảnh')
                ->icon('heroicon-o-photo')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Áp dụng toàn bộ ảnh')
                ->modalDescription(
                    'Tối ưu ảnh (resize, WebP theo cấu hình «Tối ưu hình ảnh»). '
                    . 'Nếu bật Watermark trên trang: thêm đóng dấu trước khi tối ưu. '
                    . 'Chế độ chỉ tối ưu: bỏ qua file đã là .webp. '
                    . 'Ảnh WP được backup gốc trên Laravel khi có chỉnh sửa.'
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

    private function resolveSitesQuery()
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }
}
