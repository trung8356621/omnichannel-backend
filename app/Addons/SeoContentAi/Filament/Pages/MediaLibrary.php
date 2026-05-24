<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Services\SeoMediaImageEditorResolverService;
use App\Addons\SeoContentAi\Services\SeoMediaWpEditStagingService;
use App\Addons\SeoContentAi\Services\SeoWpMediaEditedPendingService;
use App\Addons\SeoContentAi\Services\GeneratedImageLibraryService;
use App\Addons\SeoContentAi\Services\MediaLibraryArticleResolver;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryImageActionService;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryService;
use App\Addons\SeoContentAi\Services\WordPressMediaLibraryService;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class MediaLibrary extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Thư viện hình ảnh';

    protected static ?string $title = 'Thư viện hình ảnh';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'seo-content-ai::filament.pages.media-library';

    #[Url]
    public string $activeTab = 'original';

    /** @var int|string|null */
    #[Url]
    public $siteId = null;

    #[Url]
    public ?string $filterMonth = null;

    #[Url]
    public ?string $filterSearch = null;

    #[Url]
    public int $page = 1;

    /** @var list<array<string, mixed>> */
    public array $images = [];

    public int $total = 0;

    public int $totalPages = 1;

    public ?string $loadError = null;

    public ?string $editingKey = null;

    public string $editingSlug = '';

    /** @var array{id: int, url: string, wp_attachment_id: int} */
    public array $editingContext = [];

    public bool $previewOpen = false;

    /** @var array<string, mixed>|null */
    public ?array $previewImage = null;

    public bool $previewBusy = false;

    public ?string $previewMessage = null;

    public ?string $previewMessageType = null;

    public bool $previewCanRestore = false;

    public bool $previewCanOptimize = false;

    public bool $previewCanSyncToWp = false;

    public bool $previewPendingWpSync = false;

    public ?string $previewProcessingStatus = null;

    #[On('seo-media-library-refresh')]
    public function refreshLibrary(): void
    {
        $this->loadImages();
    }

    #[On('seo-magic-eraser-saved')]
    public function onMagicEraserSaved(string $url, ?int $imageId = null, bool $pendingWpSync = false): void
    {
        if (is_array($this->previewImage)) {
            $this->previewImage['url'] = $url;
            if ($imageId !== null && $imageId > 0) {
                $this->previewImage['seo_media_id'] = $imageId;
            }
        }

        if ($pendingWpSync) {
            $this->previewPendingWpSync = true;
        }

        $mediaId = $imageId ?? (int) ($this->previewImage['seo_media_id'] ?? 0);
        $media = $mediaId > 0 ? SeoMedia::query()->find($mediaId) : null;
        if ($media !== null) {
            $this->previewPendingWpSync = app(SeoMediaWpEditStagingService::class)->canSyncToWordPress($media);
        } elseif ($pendingWpSync) {
            $this->previewPendingWpSync = true;
        }

        $this->previewMessage = 'Đã lưu chỉnh sửa ảnh.';
        $this->previewMessageType = 'success';
        $this->previewOpen = true;
        $this->syncPreviewWpSyncState();
        $this->loadImages();
    }

    public function openImageEditor(): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Không tìm thấy domain')->danger()->send();

            return;
        }

        try {
            $resolved = app(SeoMediaImageEditorResolverService::class)
                ->resolve($site, $this->previewImage);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Không mở được trình chỉnh sửa')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $imageId = (int) $resolved['seo_media_id'];
        $this->previewImage['seo_media_id'] = $imageId;
        if ((int) ($this->previewImage['wp_attachment_id'] ?? 0) > 0) {
            $this->previewImage['kind'] = 'wordpress';
        }

        $this->previewPendingWpSync = false;
        $this->previewOpen = false;

        $this->js('window.open(' . json_encode($resolved['editor_url']) . ', "_blank")');
    }

    public function previewSyncToWordPress(): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Không tìm thấy domain')->danger()->send();

            return;
        }

        $mediaId = (int) ($this->previewImage['seo_media_id'] ?? 0);
        $media = SeoMedia::query()
            ->where('site_id', $site->id)
            ->whereKey($mediaId)
            ->first();

        if ($media === null) {
            Notification::make()->title('Không tìm thấy bản staging')->warning()->send();

            return;
        }

        $this->previewBusy = true;
        $this->previewMessage = null;

        $result = app(SeoMediaWpEditStagingService::class)->syncStagingToWordPress($site, $media);

        $this->previewBusy = false;

        if (! ($result['success'] ?? false)) {
            $this->previewMessage = (string) ($result['message'] ?? 'Đồng bộ thất bại.');
            $this->previewMessageType = 'error';
            Notification::make()->title($this->previewMessage)->warning()->send();

            return;
        }

        $wpUrl = (string) ($result['url'] ?? $this->previewImage['url'] ?? '');
        if ($wpUrl !== '') {
            $this->previewImage['url'] = $wpUrl;
        }

        $this->previewPendingWpSync = false;
        $this->previewMessage = (string) ($result['message'] ?? 'Đã đồng bộ lên WordPress.');
        $this->previewMessageType = 'success';
        $this->syncPreviewProcessingState($this->previewImage);
        $this->syncPreviewWpSyncState();

        Notification::make()->title($this->previewMessage)->success()->send();

        $this->loadImages();
    }

    public function mount(): void
    {
        if ($this->siteId === null) {
            $firstSite = $this->resolveSitesQuery()->first();
            $this->siteId = $firstSite instanceof Site ? (int) $firstSite->id : null;
        }

        $this->normalizeFilters();
        $this->loadImages();
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'generated') {
            $this->activeTab = 'local';
        }
    }

    public function updated($propertyName): void
    {
        if (in_array($propertyName, ['activeTab', 'siteId', 'filterMonth'], true)) {
            $this->normalizeFilters();
            $this->page = 1;
            $this->loadImages();

            return;
        }

        if ($propertyName === 'page') {
            $this->loadImages();
        }
    }

    public function clearMonthFilter(): void
    {
        $this->filterMonth = null;
        $this->page = 1;
        $this->loadImages();
    }

    public function clearSearchFilter(): void
    {
        $this->filterSearch = null;
        $this->page = 1;
        $this->loadImages();
    }

    public function beginSlugEdit(
        string $key,
        string $slug,
        int $imageId,
        string $url,
        int $wpAttachmentId,
        string $kind = 'local',
        int $seoMediaId = 0,
    ): void {
        $this->editingKey = $key;
        $this->editingSlug = $slug;
        $this->editingContext = [
            'id' => $imageId,
            'url' => $url,
            'wp_attachment_id' => $wpAttachmentId,
            'kind' => $kind,
            'seo_media_id' => $seoMediaId > 0 ? $seoMediaId : $imageId,
        ];
    }

    public function cancelSlugEdit(): void
    {
        $this->editingKey = null;
        $this->editingSlug = '';
        $this->editingContext = [];
    }

    public function saveSlugEdit(): void
    {
        if ($this->editingKey === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()
                ->title('Không tìm thấy domain')
                ->danger()
                ->send();

            return;
        }

        $newSlug = Str::slug(trim($this->editingSlug));
        if ($newSlug === '') {
            Notification::make()
                ->title('Slug không hợp lệ')
                ->danger()
                ->send();

            return;
        }

        $context = $this->editingContext;
        $this->cancelSlugEdit();

        if ($this->activeTab === 'local') {
            $kind = (string) ($context['kind'] ?? 'local');
            if ($kind === 'generated') {
                $result = app(GeneratedImageLibraryService::class)->updateSlug(
                    $site,
                    (int) ($context['id'] ?? 0),
                    $newSlug,
                );
            } else {
                $media = SeoMedia::query()
                    ->where('site_id', $site->id)
                    ->whereKey((int) ($context['seo_media_id'] ?? $context['id'] ?? 0))
                    ->first();

                if ($media === null) {
                    $result = [
                        'success' => false,
                        'message' => 'Không tìm thấy ảnh nội bộ.',
                    ];
                } else {
                    try {
                        app(SeoMediaLibraryService::class)->renameLocalBySlug($media, $newSlug);
                        $result = [
                            'success' => true,
                            'message' => 'Đã cập nhật slug file trên Laravel storage.',
                        ];
                    } catch (\InvalidArgumentException|\RuntimeException $e) {
                        $result = [
                            'success' => false,
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            }
        } else {
            $result = app(WordPressMediaLibraryService::class)->updateSlug(
                $site,
                (int) ($context['wp_attachment_id'] ?? $context['id'] ?? 0),
                $newSlug,
                (string) ($context['url'] ?? ''),
            );
        }

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title('Không cập nhật được slug')
                ->body((string) ($result['message'] ?? ''))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Đã cập nhật slug')
            ->body((string) ($result['message'] ?? ''))
            ->success()
            ->send();

        $this->loadImages();
    }

    public function loadImages(): void
    {
        $this->cancelSlugEdit();
        $this->loadError = null;
        $this->images = [];
        $this->total = 0;
        $this->totalPages = 1;

        if ($this->siteId === null || $this->siteId <= 0) {
            $this->loadError = 'Chọn tên miền để xem thư viện ảnh.';

            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            $this->loadError = 'Không tìm thấy domain.';

            return;
        }

        $month = filled($this->filterMonth) ? (string) $this->filterMonth : null;

        $search = filled($this->filterSearch) ? (string) $this->filterSearch : null;

        $result = match ($this->activeTab) {
            'local', 'generated' => app(SeoMediaLibraryService::class)->fetch(
                $site,
                $month,
                $this->page,
                $search,
            ),
            default => app(WordPressMediaLibraryService::class)->fetch(
                $site,
                $month,
                $this->page,
                search: $search,
            ),
        };

        $images = is_array($result['images'] ?? null) ? $result['images'] : [];
        $this->images = app(MediaLibraryArticleResolver::class)->enrichImages((int) $site->id, $images);
        $this->total = (int) ($result['total'] ?? 0);
        $this->totalPages = max(1, (int) ($result['total_pages'] ?? 1));
        $this->page = max(1, (int) ($result['page'] ?? $this->page));
        $this->loadError = filled($result['error'] ?? null) ? (string) $result['error'] : null;
    }

    public function previousPage(): void
    {
        if ($this->page <= 1) {
            return;
        }

        $this->page--;
        $this->loadImages();
    }

    public function nextPage(): void
    {
        if ($this->page >= $this->totalPages) {
            return;
        }

        $this->page++;
        $this->loadImages();
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSitesProperty(): Collection
    {
        return $this->resolveSitesQuery()->get();
    }

    public function openImagePreview(array $image): void
    {
        if ($this->activeTab === 'original') {
            $image['kind'] = 'wordpress';
        } elseif (in_array($this->activeTab, ['local', 'generated'], true) && empty($image['kind'])) {
            $image['kind'] = 'local';
        }

        if ($this->siteId !== null && $this->siteId > 0) {
            $image = app(SeoWpMediaEditedPendingService::class)
                ->applyPendingToImageRow((int) $this->siteId, $image);
        }

        $this->previewImage = $image;
        $this->previewOpen = true;
        $this->previewBusy = false;
        $this->previewMessage = null;
        $this->previewMessageType = null;
        $this->syncPreviewProcessingState($image);
    }

    public function closeImagePreview(): void
    {
        $this->previewOpen = false;
        $this->previewImage = null;
        $this->previewBusy = false;
        $this->previewMessage = null;
        $this->previewPendingWpSync = false;
        $this->previewCanSyncToWp = false;
    }

    public function previewApplyWatermark(): void
    {
        $this->runPreviewAction('watermark');
    }

    public function previewOptimize(): void
    {
        $this->runPreviewAction('optimize');
    }

    public function previewRestore(): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Không tìm thấy domain')->danger()->send();

            return;
        }

        $this->previewBusy = true;
        $this->previewMessage = null;

        $result = app(SeoMediaLibraryImageActionService::class)->restore($site, $this->previewImage);

        $this->previewBusy = false;

        if (! ($result['success'] ?? false)) {
            $this->previewMessage = (string) ($result['message'] ?? 'Không khôi phục được.');
            $this->previewMessageType = 'error';
            Notification::make()->title($this->previewMessage)->warning()->send();

            return;
        }

        $this->previewImage['url'] = (string) ($result['url'] ?? $this->previewImage['url']);
        $this->previewMessage = (string) ($result['message'] ?? 'Đã khôi phục.');
        $this->previewMessageType = 'success';
        $this->syncPreviewProcessingState($this->previewImage);

        $wpAttachmentId = (int) ($this->previewImage['wp_attachment_id'] ?? 0);
        if ($wpAttachmentId > 0) {
            app(SeoMediaWpEditStagingService::class)->resetStagingFromWordPressBackup($site, $wpAttachmentId);
        }

        $this->previewPendingWpSync = false;
        $this->syncPreviewWpSyncState();

        Notification::make()->title($this->previewMessage)->success()->send();

        $this->loadImages();
    }

    /**
     * @param  array<string, mixed>|null  $image
     */
    private function syncPreviewProcessingState(?array $image): void
    {
        $this->previewCanRestore = false;
        $this->previewCanOptimize = false;
        $this->previewProcessingStatus = null;

        if ($image === null || $this->siteId === null || $this->siteId <= 0) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }

        $state = app(SeoMediaLibraryImageActionService::class)->previewState($site, $image);
        $this->previewCanRestore = (bool) ($state['can_restore'] ?? false);
        $this->previewCanOptimize = (bool) ($state['can_optimize'] ?? false);
        $this->previewProcessingStatus = (string) ($state['status'] ?? 'original');
        $this->syncPreviewWpSyncState();
    }

    private function syncPreviewWpSyncState(): void
    {
        $this->previewCanSyncToWp = false;

        if (! is_array($this->previewImage) || $this->siteId === null || $this->siteId <= 0) {
            return;
        }

        $siteId = (int) $this->siteId;
        $wpAttachmentId = (int) ($this->previewImage['wp_attachment_id'] ?? $this->previewImage['id'] ?? 0);
        $pendingService = app(SeoWpMediaEditedPendingService::class);

        if ($wpAttachmentId > 0 && $pendingService->canSyncPending($siteId, $wpAttachmentId)) {
            $this->previewCanSyncToWp = true;
            $this->previewPendingWpSync = true;

            return;
        }

        $mediaId = (int) ($this->previewImage['seo_media_id'] ?? 0);
        if ($mediaId > 0) {
            $media = SeoMedia::query()->find($mediaId);
            $this->previewCanSyncToWp = app(SeoMediaWpEditStagingService::class)->canSyncToWordPress($media);
        }
    }

    private function runPreviewAction(string $action): void
    {
        if ($this->previewImage === null || $this->siteId === null) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Không tìm thấy domain')->danger()->send();

            return;
        }

        $this->previewBusy = true;
        $this->previewMessage = null;

        $service = app(SeoMediaLibraryImageActionService::class);
        $result = $action === 'watermark'
            ? $service->applyWatermark($site, $this->previewImage)
            : $service->optimize($site, $this->previewImage);

        $this->previewBusy = false;

        if (! ($result['success'] ?? false)) {
            $this->previewMessage = (string) ($result['message'] ?? 'Thao tác thất bại.');
            $this->previewMessageType = 'error';
            Notification::make()
                ->title($this->previewMessage)
                ->warning()
                ->send();

            return;
        }

        $this->previewImage['url'] = (string) ($result['url'] ?? $this->previewImage['url']);
        $this->previewMessage = (string) ($result['message'] ?? 'Đã xử lý.');
        $this->previewMessageType = 'success';

        $this->previewCanRestore = (bool) ($result['can_restore'] ?? false);
        $this->previewCanOptimize = (bool) ($result['can_optimize'] ?? false);

        Notification::make()
            ->title($this->previewMessage)
            ->success()
            ->send();

        $this->loadImages();

        if ($this->previewImage !== null) {
            foreach ($this->images as $row) {
                $sameKind = ($row['kind'] ?? '') === ($this->previewImage['kind'] ?? '');
                $sameId = (int) ($row['id'] ?? 0) === (int) ($this->previewImage['id'] ?? 0);
                if ($sameKind && $sameId) {
                    $this->previewImage = $row;
                    $this->previewImage['url'] = (string) ($result['url'] ?? $row['url']);

                    break;
                }
            }
        }
    }

    public function filterMonthLabel(): string
    {
        if (! filled($this->filterMonth)) {
            return '';
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m', (string) $this->filterMonth)->format('m/Y');
        } catch (\Throwable) {
            return (string) $this->filterMonth;
        }
    }

    private function normalizeFilters(): void
    {
        $siteId = $this->siteId;
        if ($siteId === null || $siteId === '' || (int) $siteId <= 0) {
            $this->siteId = null;
        } else {
            $this->siteId = (int) $siteId;
        }

        $month = trim((string) ($this->filterMonth ?? ''));
        $this->filterMonth = $month !== '' ? $month : null;

        $search = trim((string) ($this->filterSearch ?? ''));
        $this->filterSearch = $search !== '' ? $search : null;

        if ($this->activeTab === 'generated') {
            $this->activeTab = 'local';
        }
    }

    private function resolveSitesQuery()
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    /**
     * @return list<array{id: int, domain: string}>
     */
    public function getSitesListForJs(): array
    {
        return $this->sites->map(fn (Site $site): array => [
            'id' => (int) $site->id,
            'domain' => (string) $site->domain,
        ])->values()->all();
    }
}
