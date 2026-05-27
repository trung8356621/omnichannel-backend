<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Services\ArticleEditorHtmlSanitizeService;
use App\Addons\SeoContentAi\Services\ArticleEditorHistoryService;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoPayloadService;
use App\Addons\SeoContentAi\Services\ArticleFaqBodySyncService;
use App\Addons\SeoContentAi\Services\ArticleFaqEditorService;
use App\Addons\SeoContentAi\Exceptions\FaqManualExtractException;
use App\Addons\SeoContentAi\Services\ArticleFaqExtractDebugService;
use App\Addons\SeoContentAi\Services\ArticleFaqWordPressRestoreService;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\ArticleEditorMediaAiService;
use App\Addons\SeoContentAi\Services\ArticleFaqManualExtractService;
use App\Addons\SeoContentAi\Services\ArticleFaqWordPressImportService;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Services\ArticleMediaLocalService;
use App\Addons\SeoContentAi\Services\WordPressAttachmentRenameService;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Services\MediaLibraryArticleResolver;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryService;
use App\Addons\SeoContentAi\Services\WordPressMediaLibraryService;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.edit-article';

    public string $articleTitle = '';

    public string $articleSlug = '';

    public string $articleStatus = 'draft';

    public string $visibility = 'public';

    public bool $editingStatus = false;

    public bool $editingVisibility = false;

    public bool $editingPublishAt = false;

    public string $publishDay = '';

    public string $publishMonth = '';

    public string $publishYear = '';

    public string $publishHour = '';

    public string $publishMinute = '';

    public ?string $featuredImageUrl = null;

    /** @var array<int, array{id: int, url: string}> */
    public array $productGallery = [];

    public bool $mediaPickerOpen = false;

    /** @var 'featured'|'gallery'|'editor-block' */
    public string $mediaPickerMode = 'featured';

    public ?string $mediaPickerTargetBlockId = null;

    public string $mediaPickerSearch = '';

    /** @var list<array<string, mixed>> */
    public array $mediaPickerImages = [];

    public int $mediaPickerPage = 1;

    public int $mediaPickerTotalPages = 1;

    public ?string $mediaPickerError = null;

    public bool $mediaPickerLoading = false;

    /** @var 'original'|'local' */
    public string $mediaPickerTab = 'original';

    public bool $editingSlug = false;

    /** @var 'save'|'sync'|null Thu thập HTML sau khi flush FAQ (Lưu / Đồng bộ WP). */
    public ?string $pendingEditorCollectTarget = null;

    public string $editorHtml = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->syncTitleFromWordPressWhenAllowed();
        $this->hydrateArticleState();
        $this->importFaqsFromWordPressOnLoad();
    }

    /**
     * Khi mở trang sửa: lấy tiêu đề mới nhất từ WP nếu bài chưa chỉnh local (webhook có thể đã cập nhật DB).
     */
    private function syncTitleFromWordPressWhenAllowed(): void
    {
        if ((int) ($this->record->wp_post_id ?? 0) <= 0) {
            return;
        }

        if (app(ArticleWordPressSyncFlagService::class)->shouldBlockWordPressImport($this->record)) {
            return;
        }

        $post = app(WordPressArticleContentService::class)->fetchFromWordPress($this->record, importFaqs: false);
        if ($post === []) {
            return;
        }

        $this->record->refresh();
    }

    private function importFaqsFromWordPressOnLoad(): void
    {
        if ((int) ($this->record->wp_post_id ?? 0) > 0) {
            $this->record->loadCount('faqs');
            $needsWpPull = $this->record->faqs_count === 0
                || ! $this->articleHasStoredWordPressFaqs($this->record);
            if ($needsWpPull) {
                app(WordPressArticleContentService::class)->fetchFromWordPress($this->record);
                $this->record->refresh();
                $this->editorHtml = app(WordPressArticleContentService::class)->resolveEditorHtml($this->record);
            }
        }

        $result = app(ArticleFaqWordPressImportService::class)
            ->importWhenPanelEmpty($this->record, $this->editorHtml);

        if ($result['imported'] && ($result['faq_count'] ?? 0) > 0) {
            $this->record->load('faqs');
            $editorHtml = (string) ($result['editor_html'] ?? $this->editorHtml);
            if ($editorHtml !== '') {
                $this->editorHtml = $editorHtml;
            }

            $this->dispatch(
                'article-faqs-extracted',
                faqs: $result['faqs'],
                editorHtml: $editorHtml,
            );

            return;
        }

        $this->dispatchFaqExtractDebugIfPresent($result['extract_debug'] ?? null);
    }

    private function articleHasStoredWordPressFaqs(\App\Addons\SeoContentAi\Models\SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', 'wp_faqs')?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return false;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [];
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('restoreFromWordPress')
                ->label('Khôi phục từ WordPress')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->iconButton()
                ->tooltip('Lấy lại bài viết gốc từ WordPress')
                ->visible(fn (): bool => (int) ($this->record->wp_post_id ?? 0) > 0)
                ->requiresConfirmation()
                ->modalHeading('Khôi phục bài gốc WordPress')
                ->modalDescription('Thay nội dung editor và xóa FAQ panel bằng bản gốc từ WordPress. Các chỉnh sửa chưa lưu hoặc chưa đồng bộ trên SEO sẽ bị ghi đè.')
                ->modalSubmitActionLabel('Khôi phục')
                ->action(fn (): mixed => $this->restoreArticleFromWordPress()),
            Actions\DeleteAction::make()
                ->icon('heroicon-o-trash')
                ->iconButton()
                ->tooltip('Xóa bài viết'),
        ];
    }

    public function restoreArticleFromWordPress(): void
    {
        $restore = app(ArticleFaqWordPressRestoreService::class)->restoreFullArticleFromWordPress($this->record);

        if (! ($restore['restored'] ?? false) || ! filled($restore['editor_html'] ?? null)) {
            Notification::make()
                ->title('Không khôi phục được')
                ->body((string) ($restore['message'] ?? 'Không lấy được nội dung từ WordPress.'))
                ->warning()
                ->send();

            return;
        }

        $this->editorHtml = (string) $restore['editor_html'];
        $this->record->refresh();
        $this->hydrateArticleState();

        app(ArticleWordPressSyncFlagService::class)->clearAll($this->record);

        $this->featuredImageUrl = app(WordPressArticleContentService::class)->resolveFeaturedImageUrl($this->record);
        $this->productGallery = $this->isProduct()
            ? app(WordPressArticleContentService::class)->resolveProductGallery($this->record)
            : [];

        $this->dispatch(
            'article-faqs-extracted',
            faqs: app(ArticleFaqEditorService::class)->payloadForArticle($this->record),
            editorHtml: $this->editorHtml,
        );

        $this->dispatch('article-faq-extract-debug-cleared');
        $this->js('window.dispatchEvent(new CustomEvent("article-faq-extract-debug-cleared"))');

        app(SeoAnalyzerService::class)->analyze($this->record->fresh());

        Notification::make()
            ->title('Đã khôi phục từ WordPress')
            ->body((string) ($restore['message'] ?? 'Nội dung editor đã thay bằng bản gốc WordPress.'))
            ->success()
            ->send();
    }

    public function hasWpDataOutOfSync(): bool
    {
        return app(ArticleWordPressSyncFlagService::class)->hasDataOutOfSync($this->record);
    }

    protected function hydrateArticleState(): void
    {
        $service = app(WordPressArticleContentService::class);
        $flags = app(ArticleWordPressSyncFlagService::class);

        $this->articleTitle = $flags->decodeWordPressText((string) ($this->record->title ?? ''));
        $this->articleSlug = $service->resolveSlug($this->record);
        $this->articleStatus = (string) ($this->record->status ?? 'draft');
        $this->visibility = $this->articleStatus === 'private' ? 'private' : 'public';
        $this->featuredImageUrl = $service->resolveFeaturedImageUrl($this->record);
        $this->productGallery = $this->isProduct()
            ? $service->resolveProductGallery($this->record)
            : [];
        $this->editorHtml = $service->resolveEditorHtml($this->record);
        $this->syncPublishDatePartsFromRecord();
    }

    public function isProduct(): bool
    {
        $type = strtolower(trim((string) ($this->record->type ?? '')));
        if (in_array($type, ['product', 'e-commerce'], true)) {
            return true;
        }

        $this->record->loadMissing('articleMetas');
        $wpPostType = strtolower(trim((string) (
            $this->record->articleMetas->firstWhere('meta_key', 'wp_post_type')?->meta_value ?? ''
        )));

        return $wpPostType === 'product';
    }

    public function isTaxonomyArticle(): bool
    {
        return app(WordPressArticleContentService::class)->isTaxonomyRecord($this->record);
    }

    public function supportsProductGallery(): bool
    {
        return $this->isProduct() && ! $this->isTaxonomyArticle();
    }

    #[On('open-editor-block-media-picker')]
    public function openEditorBlockMediaPicker(string $blockId): void
    {
        $this->prepareMediaPicker('editor-block', $blockId);
    }

    /**
     * @param  array{title?: string, body?: string, status?: string}  $payload
     */
    #[On('seo-article-editor-notify')]
    public function handleEditorNotify(array $payload = []): void
    {
        $notification = Notification::make()
            ->title((string) ($payload['title'] ?? ''))
            ->body((string) ($payload['body'] ?? ''));

        match ((string) ($payload['status'] ?? 'success')) {
            'danger', 'error' => $notification->danger(),
            'warning' => $notification->warning(),
            default => $notification->success(),
        };

        $notification->send();
    }

    public function prepareMediaPicker(string $mode = 'featured', ?string $blockId = null): void
    {
        if ($mode !== 'editor-block' && (int) ($this->record->wp_post_id ?? 0) <= 0) {
            Notification::make()
                ->title('Chưa liên kết WordPress')
                ->body('Đồng bộ bài từ domain trước khi chọn ảnh WordPress.')
                ->warning()
                ->send();

            $this->mediaPickerOpen = false;
            $this->dispatch('close-article-media-modal');

            return;
        }

        if ($mode === 'editor-block') {
            $blockId = trim((string) ($blockId ?? ''));
            if ($blockId === '') {
                Notification::make()
                    ->title('Không xác định được khối ảnh')
                    ->warning()
                    ->send();

                $this->mediaPickerOpen = false;
                $this->dispatch('close-article-media-modal');

                return;
            }

            $this->mediaPickerTargetBlockId = $blockId;
            $this->mediaPickerMode = 'editor-block';
        } else {
            $this->mediaPickerTargetBlockId = null;
            $this->mediaPickerMode = $mode === 'gallery' ? 'gallery' : 'featured';
        }

        $this->mediaPickerTab = 'original';
        $this->mediaPickerPage = 1;
        $this->mediaPickerError = null;
        $this->mediaPickerImages = [];
        $this->mediaPickerLoading = true;
        $this->mediaPickerOpen = true;
        $this->dispatch('open-article-media-modal');
        $this->loadMediaPickerImages();
    }

    public function setMediaPickerTab(string $tab): void
    {
        $tab = $tab === 'local' ? 'local' : 'original';
        if ($this->mediaPickerTab === $tab) {
            return;
        }

        $this->mediaPickerTab = $tab;
        $this->mediaPickerPage = 1;
        $this->loadMediaPickerImages();
    }

    public function closeMediaPicker(): void
    {
        $this->mediaPickerOpen = false;
        $this->mediaPickerError = null;
        $this->mediaPickerImages = [];
        $this->mediaPickerLoading = false;
        $this->mediaPickerTargetBlockId = null;
    }

    public function updatedMediaPickerSearch(): void
    {
        $this->mediaPickerPage = 1;
        $this->loadMediaPickerImages();
    }

    public function mediaPickerPreviousPage(): void
    {
        if ($this->mediaPickerPage <= 1) {
            return;
        }

        $this->mediaPickerPage--;
        $this->loadMediaPickerImages();
    }

    public function mediaPickerNextPage(): void
    {
        if ($this->mediaPickerPage >= $this->mediaPickerTotalPages) {
            return;
        }

        $this->mediaPickerPage++;
        $this->loadMediaPickerImages();
    }

    public function loadMediaPickerImages(): void
    {
        $this->mediaPickerLoading = true;
        $this->mediaPickerError = null;

        $this->record->loadMissing('site');
        $site = $this->record->site;
        if ($site === null) {
            $this->mediaPickerError = 'Không tìm thấy domain.';
            $this->mediaPickerLoading = false;

            return;
        }

        $search = trim($this->mediaPickerSearch);
        $articleId = (int) $this->record->id;
        $library = app(SeoMediaLibraryService::class);

        if ($this->mediaPickerTab === 'local') {
            $library->assignRecentOrphanMediaToArticle($site, $articleId);
        }

        $result = $this->mediaPickerTab === 'local'
            ? $library->fetch(
                $site,
                null,
                $this->mediaPickerPage,
                $search !== '' ? $search : null,
                48,
                $articleId,
            )
            : app(WordPressMediaLibraryService::class)->fetch(
                $site,
                null,
                $this->mediaPickerPage,
                48,
                $search !== '' ? $search : null,
            );

        $images = is_array($result['images'] ?? null) ? $result['images'] : [];
        $this->mediaPickerImages = $this->mediaPickerTab === 'local'
            ? app(MediaLibraryArticleResolver::class)->enrichImages((int) $site->id, $images)
            : $images;
        $this->mediaPickerTotalPages = max(1, (int) ($result['total_pages'] ?? 1));
        $this->mediaPickerPage = max(1, (int) ($result['page'] ?? $this->mediaPickerPage));
        $this->mediaPickerError = filled($result['error'] ?? null) ? (string) $result['error'] : null;
        $this->mediaPickerLoading = false;
    }

    public function selectMediaFromPicker(
        int $wpAttachmentId,
        string $url,
        string $alt = '',
        string $slug = '',
        int $seoMediaId = 0,
    ): void {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $seoMediaId = max(0, $seoMediaId);
        $wpAttachmentId = max(0, $wpAttachmentId);
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

        if ($this->mediaPickerMode === 'editor-block') {
            if ($this->mediaPickerTab === 'local' && $seoMediaId <= 0 && $wpAttachmentId <= 0) {
                return;
            }

            if ($this->mediaPickerTab !== 'local' && $wpAttachmentId <= 0) {
                return;
            }

            $blockId = trim((string) ($this->mediaPickerTargetBlockId ?? ''));
            if ($blockId === '') {
                return;
            }

            $this->dispatch(
                'editor-block-image-selected',
                blockId: $blockId,
                attachmentId: $wpAttachmentId,
                seoMediaId: $seoMediaId,
                url: $url,
                alt: trim($alt),
                slug: trim($slug),
            );

            $this->mediaPickerTargetBlockId = null;
            $this->mediaPickerOpen = false;
            $this->dispatch('close-article-media-modal');

            Notification::make()
                ->title('Đã chọn ảnh cho khối')
                ->success()
                ->send();

            return;
        }

        if ($localRefId <= 0) {
            return;
        }

        $localMedia = app(ArticleMediaLocalService::class);

        if ($this->mediaPickerMode === 'gallery') {
            if (! $this->supportsProductGallery()) {
                Notification::make()
                    ->title('Album không áp dụng')
                    ->body('Danh mục chỉ hỗ trợ ảnh đại diện.')
                    ->warning()
                    ->send();

                return;
            }

            $this->productGallery = $localMedia->appendGalleryLocal($this->record, $localRefId, $url);
            $title = 'Đã thêm vào album (lưu cục bộ)';
        } else {
            $localMedia->applyFeaturedLocal($this->record, $localRefId, $url);
            $this->featuredImageUrl = trim($url);
            $title = 'Đã chọn ảnh đại diện (lưu cục bộ)';
        }

        $this->record->refresh();
        $this->dispatch('close-article-media-modal');

        Notification::make()
            ->title($title)
            ->body('Bấm «Đồng bộ» để đẩy ảnh lên WordPress.')
            ->success()
            ->send();
    }

    public function getPermalinkBase(): string
    {
        $this->record->loadMissing('site');
        if (! $this->record->site) {
            return '';
        }

        return app(WordPressArticleContentService::class)->getPermalinkBase($this->record->site);
    }

    public function getDisplaySlug(): string
    {
        return $this->articleSlug !== '' ? $this->articleSlug : 'sample-post';
    }

    public function getArticlePermalink(): string
    {
        return app(WordPressArticleContentService::class)->resolvePermalink($this->record);
    }

    public function getStatusLabel(): string
    {
        return match ($this->articleStatus) {
            'published' => 'Đã xuất bản',
            'scheduled' => 'Hẹn giờ',
            'private' => 'Riêng tư',
            default => 'Bản nháp',
        };
    }

    public function getPublishedAtLabel(): ?string
    {
        $publishedAt = $this->resolvePublishAtForEditor();
        if ($publishedAt === null) {
            return null;
        }

        return $publishedAt->timezone(config('app.timezone'))->format('d/m/Y H:i');
    }

    public function getPublishWhenLabel(): string
    {
        $publishedAt = $this->resolvePublishAtForEditor();
        if ($publishedAt === null) {
            return 'Chưa đặt lịch';
        }

        return $this->formatWpScheduleLabel($publishedAt);
    }

    public function getVisibilityLabel(): string
    {
        return $this->visibility === 'private' ? 'Riêng tư' : 'Công khai';
    }

    public function getStatusLabelForPublishBox(): string
    {
        return match ($this->articleStatus) {
            'published' => 'Đã xuất bản',
            'scheduled' => 'Đã lên lịch',
            'private' => 'Riêng tư',
            default => 'Bản nháp',
        };
    }

    public function startStatusEdit(): void
    {
        $this->editingStatus = true;
    }

    public function cancelStatusEdit(): void
    {
        $this->editingStatus = false;
    }

    public function applyStatusEdit(): void
    {
        $this->visibility = $this->articleStatus === 'private' ? 'private' : 'public';

        $this->editingStatus = false;
    }

    public function startVisibilityEdit(): void
    {
        $this->editingVisibility = true;
    }

    public function cancelVisibilityEdit(): void
    {
        $this->editingVisibility = false;
        $this->visibility = $this->articleStatus === 'private' ? 'private' : 'public';
    }

    public function applyVisibilityEdit(): void
    {
        if ($this->visibility === 'private') {
            $this->articleStatus = 'private';
        } elseif ($this->articleStatus === 'private') {
            $this->articleStatus = 'draft';
        }

        $this->editingVisibility = false;
    }

    public function startPublishAtEdit(): void
    {
        $this->editingPublishAt = true;
        $this->randomizePublishAtFuture();
    }

    public function cancelPublishAtEdit(): void
    {
        $this->editingPublishAt = false;
        $this->syncPublishDatePartsFromRecord();
    }

    public function applyPublishAtEdit(): void
    {
        $dt = $this->buildPublishAtFromParts();
        if ($dt === null) {
            Notification::make()
                ->title('Ngày giờ không hợp lệ')
                ->body('Vui lòng kiểm tra lại ngày/giờ xuất bản.')
                ->warning()
                ->send();

            return;
        }

        $this->publishYear = $dt->format('Y');
        $this->publishMonth = $dt->format('m');
        $this->publishDay = $dt->format('d');
        $this->publishHour = $dt->format('H');
        $this->publishMinute = $dt->format('i');

        if ($this->visibility !== 'private') {
            $this->articleStatus = $dt->greaterThan(now(config('app.timezone'))) ? 'scheduled' : 'published';
        }

        $this->editingPublishAt = false;
    }

    public function randomizePublishAtFuture(): void
    {
        $base = now(config('app.timezone'))->addHours(random_int(1, 8));
        $minutePool = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];
        $minute = $minutePool[array_rand($minutePool)];
        $dt = $base->copy()->minute($minute)->second(0);

        $this->publishYear = $dt->format('Y');
        $this->publishMonth = $dt->format('m');
        $this->publishDay = $dt->format('d');
        $this->publishHour = $dt->format('H');
        $this->publishMinute = $dt->format('i');

        if ($this->visibility !== 'private') {
            $this->articleStatus = 'scheduled';
        }
    }

    public function requestSaveArticle(): void
    {
        $this->pendingEditorCollectTarget = 'save';
        $this->dispatch('flush-article-faqs');
    }

    public function requestSyncToWordPress(): void
    {
        $this->pendingEditorCollectTarget = 'sync';
        $this->dispatch('flush-article-faqs');
    }

    /** Dự phòng khi flush FAQ không gọi được saveArticleFaqs (timeout phía client). */
    public function finalizePendingEditorCollect(): void
    {
        if ($this->pendingEditorCollectTarget === null) {
            return;
        }

        $target = $this->pendingEditorCollectTarget;
        $this->pendingEditorCollectTarget = null;
        $this->dispatch('collect-editor-html', target: $target);
    }

    public function getArticlePreviewUrl(): string
    {
        return route('seo.articles.preview', ['article' => $this->record->id]);
    }

    /**
     * Lưu vào Laravel (không đẩy WordPress).
     */
    public function persistArticleLocal(string $html): void
    {
        $html = app(ArticleEditorHtmlSanitizeService::class)->stripTransientEditorMarkup($html);

        $faqSync = app(ArticleFaqBodySyncService::class)->extractFromBodyWhenMissing($this->record, $html);
        $html = $faqSync['body_html'];
        if ($faqSync['extracted']) {
            $this->dispatch('article-faqs-extracted', faqs: $faqSync['faqs'], editorHtml: $html);
        } else {
            $this->dispatchFaqExtractDebugIfPresent($faqSync['extract_debug'] ?? null);
        }

        $slug = Str::slug($this->articleSlug);

        $publishAt = $this->resolvePublishAtForSave();

        $this->record->update([
            'title' => trim($this->articleTitle),
            'slug' => $slug !== '' ? $slug : null,
            'status' => $this->articleStatus,
            'published_at' => $publishAt,
            'body' => $html,
            'user_id' => auth()->id(),
        ]);

        $this->articleSlug = $slug;
        $this->editingSlug = false;
        $this->syncPublishDatePartsFromRecord();

        app(ArticlePostImagesService::class)->syncFromHtml($this->record, $html);
        $this->record->refresh();

        app(SeoAnalyzerService::class)->analyze($this->record->fresh());

        $seoResult = app(SeoAnalyzerService::class)->analyzePreview(
            $this->record->fresh(),
            $html,
            trim($this->articleTitle),
            $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
        );
        $this->dispatch('seo-analyze-result', result: $seoResult);

        $this->js('window.dispatchEvent(new CustomEvent("seo-article-saved"))');

        $saveBody = 'Nội dung chỉ lưu trên hệ thống SEO. Dùng «Đồng bộ» để đẩy lên WordPress.';
        if ($faqSync['extracted']) {
            $saveBody = 'Đã tách ' . $faqSync['faq_count'] . ' FAQ từ nội dung vào panel FAQ. ' . $saveBody;
        } elseif (! empty($faqSync['extract_debug'])) {
            $saveBody = 'Có tiêu đề FAQ trong bài nhưng chưa tách được câu hỏi/trả lời — xem debug trong khối FAQ. ' . $saveBody;
        }

        Notification::make()
            ->title('Đã lưu bài viết')
            ->body($saveBody)
            ->success()
            ->send();
    }

    /**
     * Lưu Laravel rồi đẩy lên WordPress.
     */
    public function syncArticleToWordPress(string $html): void
    {
        $this->persistArticleLocalSilent($html);

        $result = app(WordPressArticleSyncService::class)->syncForArticle($this->record->fresh());

        $this->dispatchFaqExtractDebugIfPresent($result['faq_extract_debug'] ?? null);

        if ($result['success']) {
            $syncBody = $result['message'];
            if (! empty($result['faq_extract_debug'])) {
                $headingText = trim((string) ($result['faq_extract_debug']['heading']['text'] ?? ''));
                $syncBody = ($headingText !== ''
                    ? 'Đồng bộ xong nhưng 0 FAQ (đã nhận tiêu đề: «' . $headingText . '»). Xem debug trong khối FAQ.'
                    : 'Đồng bộ xong nhưng 0 FAQ — xem debug trong khối FAQ.') . ' ' . $syncBody;
            }

            Notification::make()
                ->title('Đã đồng bộ WordPress')
                ->body($syncBody)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Đồng bộ WordPress thất bại')
            ->body($result['message'])
            ->danger()
            ->send();
    }

    private function persistArticleLocalSilent(string $html): void
    {
        $html = app(ArticleEditorHtmlSanitizeService::class)->stripTransientEditorMarkup($html);

        $faqSync = app(ArticleFaqBodySyncService::class)->extractFromBodyWhenMissing($this->record, $html);
        $html = $faqSync['body_html'];
        if ($faqSync['extracted']) {
            $this->dispatch('article-faqs-extracted', faqs: $faqSync['faqs'], editorHtml: $html);
        } else {
            $this->dispatchFaqExtractDebugIfPresent($faqSync['extract_debug'] ?? null);
        }

        $slug = Str::slug($this->articleSlug);

        $publishAt = $this->resolvePublishAtForSave();

        $this->record->update([
            'title' => trim($this->articleTitle),
            'slug' => $slug !== '' ? $slug : null,
            'status' => $this->articleStatus,
            'published_at' => $publishAt,
            'body' => $html,
            'user_id' => auth()->id(),
        ]);

        $this->articleSlug = $slug;
        $this->syncPublishDatePartsFromRecord();
        app(ArticlePostImagesService::class)->syncFromHtml($this->record, $html);
        $this->record->refresh();

        app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($this->record);
    }

    /**
     * @param  array<string, mixed>|null  $debug
     */
    private function dispatchFaqExtractDebugIfPresent(?array $debug): void
    {
        if ($debug === null || $debug === []) {
            return;
        }

        $this->dispatch('article-faq-extract-debug', debug: $debug);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEditorSeoPayload(): array
    {
        return app(ArticleEditorSeoPayloadService::class)->forArticle($this->record);
    }

    public function analyzeSeoDraft(string $html): void
    {
        $slug = Str::slug($this->articleSlug);

        $result = app(SeoAnalyzerService::class)->analyzePreview(
            $this->record,
            $html,
            trim($this->articleTitle),
            $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
        );

        $this->dispatch('seo-analyze-result', result: $result);
    }

    /**
     * Cấu hình editor (history_step lưu wp_options). Lịch sử undo/redo lưu localStorage phía client.
     *
     * @return array{history_step: int}
     */
    public function getEditorSettingsPayload(): array
    {
        return app(ArticleEditorHistoryService::class)->getSettings();
    }

    /**
     * @return array{id: int, site_id: int, title: string, ai_debug: array<string, mixed>}
     */
    public function getEditorMetaPayload(): array
    {
        return [
            'id' => (int) $this->record->id,
            'site_id' => (int) $this->record->site_id,
            'title' => (string) $this->articleTitle,
            'ai_debug' => $this->getEditorAiDebugPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getEditorAiDebugPayload(): array
    {
        if (! config('app.debug')) {
            return ['enabled' => false];
        }

        $settings = app(SeoCreateArticleSettingsService::class);

        return [
            'enabled' => true,
            'article_title' => trim((string) ($this->record->title ?? '')),
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($this->record) ?? '',
            'image' => $this->buildPromptDebugPayload($settings->getCreateImagePromptId()),
            'video' => $this->buildPromptDebugPayload($settings->getCreateVideoPromptId()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPromptDebugPayload(?int $promptId): array
    {
        if ($promptId === null) {
            return [
                'prompt_id' => null,
                'name' => '',
                'template' => '',
                'variables' => [],
            ];
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if (! $prompt instanceof SeoPrompt) {
            return [
                'prompt_id' => $promptId,
                'name' => '',
                'template' => '',
                'variables' => [],
            ];
        }

        $variableNames = collect(is_array($prompt->variables) ? $prompt->variables : [])
            ->map(static fn (array $row): string => trim((string) ($row['name'] ?? '')))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();

        $placeholderVars = [];
        foreach ($variableNames as $name) {
            $placeholderVars[$name] = '{{' . $name . '}}';
        }

        try {
            $template = app(PromptRunnerService::class)->compilePrompt($prompt, $placeholderVars);
        } catch (\Throwable) {
            $template = (string) ($prompt->markdown_content ?? '');
        }

        return [
            'prompt_id' => (int) $prompt->id,
            'name' => (string) ($prompt->name ?? ''),
            'template' => $template,
            'variables' => $variableNames,
        ];
    }

    /**
     * Danh sách ảnh trong bài (meta wp_post_images, đồng bộ từ WordPress).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEditorImagesPayload(): array
    {
        return app(ArticlePostImagesService::class)->resolveForArticle($this->record);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEditorFaqsPayload(): array
    {
        return app(ArticleFaqEditorService::class)->payloadForArticle($this->record);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFaqExtractDebugPayload(): ?array
    {
        return app(ArticleFaqExtractDebugService::class)->get($this->record);
    }

    public function clearFaqExtractDebug(): void
    {
        app(ArticleFaqExtractDebugService::class)->dismiss($this->record);
        $this->dispatch('article-faq-extract-debug-cleared');
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     */
    public function extractFaqsFromSelection(string $html, string $articleHtml = ''): void
    {
        try {
            $result = app(ArticleFaqManualExtractService::class)
                ->extractFromHtmlFragment($this->record, $html, $articleHtml);
        } catch (FaqManualExtractException $exception) {
            $this->dispatch('article-faq-extract-debug', debug: $exception->debug);

            Notification::make()
                ->title('Không tách được FAQ')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Không tách được FAQ')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        $faqs = $result['faqs'] ?? [];
        $editorHtml = (string) ($result['editor_html'] ?? '');

        $this->dispatch('article-faqs-extracted', faqs: $faqs, editorHtml: $editorHtml);

        Notification::make()
            ->title('Đã tách và lưu FAQ')
            ->body('Số mục FAQ: ' . count($faqs) . '. Nội dung FAQ trong editor đã thay bằng [omi_faq].')
            ->success()
            ->send();
    }

    public function saveArticleFaqs(array $faqs): void
    {
        $previousCount = $this->record->faqs()->count();
        $savedCount = app(ArticleFaqEditorService::class)->saveFromEditor($this->record, $faqs);

        if ($savedCount === 0) {
            $restore = app(ArticleFaqWordPressRestoreService::class)->restoreWhenFaqsCleared($this->record);

            if ($restore['restored'] && filled($restore['editor_html'] ?? null)) {
                $this->editorHtml = (string) $restore['editor_html'];
                $this->record->refresh();

                $this->dispatch(
                    'article-faqs-extracted',
                    faqs: [],
                    editorHtml: $this->editorHtml,
                );

                if ($this->pendingEditorCollectTarget !== null) {
                    $target = $this->pendingEditorCollectTarget;
                    $this->pendingEditorCollectTarget = null;
                    $this->dispatch('collect-editor-html', target: $target);

                    return;
                }

                Notification::make()
                    ->title('Đã xóa FAQ')
                    ->body((string) ($restore['message'] ?? 'Nội dung bài đã khôi phục từ WordPress.'))
                    ->success()
                    ->send();

                return;
            }

            if ($this->pendingEditorCollectTarget !== null) {
                $target = $this->pendingEditorCollectTarget;
                $this->pendingEditorCollectTarget = null;
                $this->dispatch('collect-editor-html', target: $target);

                return;
            }

            Notification::make()
                ->title('Đã xóa FAQ')
                ->body((string) ($restore['message'] ?? 'FAQ đã xóa trên hệ thống SEO.'))
                ->warning()
                ->send();

            return;
        }

        if ($savedCount < $previousCount) {
            $restore = app(ArticleFaqWordPressRestoreService::class)->restoreAfterFaqRemoved($this->record, $faqs);

            if ($restore['restored'] && filled($restore['editor_html'] ?? null)) {
                $this->editorHtml = (string) $restore['editor_html'];
                $this->record->refresh();

                $this->dispatch(
                    'article-faqs-extracted',
                    faqs: app(ArticleFaqEditorService::class)->payloadForArticle($this->record),
                    editorHtml: $this->editorHtml,
                );

                if ($this->pendingEditorCollectTarget !== null) {
                    $target = $this->pendingEditorCollectTarget;
                    $this->pendingEditorCollectTarget = null;
                    $this->dispatch('collect-editor-html', target: $target);

                    return;
                }

                Notification::make()
                    ->title('Đã xóa FAQ')
                    ->body((string) ($restore['message'] ?? 'Nội dung bài đã khôi phục từ WordPress.'))
                    ->success()
                    ->send();

                return;
            }
        }

        if ($this->pendingEditorCollectTarget !== null) {
            $target = $this->pendingEditorCollectTarget;
            $this->pendingEditorCollectTarget = null;
            $this->dispatch('collect-editor-html', target: $target);

            return;
        }

        Notification::make()
            ->title('Đã lưu FAQ')
            ->body('FAQ lưu trên hệ thống SEO. Đồng bộ WordPress khi bấm «Đồng bộ».')
            ->success()
            ->send();
    }

    public function generateArticleImageFromEditor(
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $activeBlockId = '',
    ): void {
        try {
            $result = app(ArticleEditorMediaAiService::class)->generateImage(
                $this->record,
                $selectionText,
                $selectionHtml,
                $userBrief,
                $activeBlockId,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->dispatch('article-ai-media-failed', type: 'image', message: $exception->getMessage());

            Notification::make()
                ->title('Không tạo được ảnh')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->dispatch(
            'article-ai-image-generated',
            url: $result['url'],
            activeBlockId: $activeBlockId,
            seoMediaId: (int) ($result['seo_media_id'] ?? 0),
            status: (string) ($result['status'] ?? 'processing'),
            mediaType: 'image',
        );

        Notification::make()
            ->title('Đang tạo ảnh')
            ->body('Đã chèn placeholder. Ảnh thật sẽ tự cập nhật khi xử lý xong.')
            ->success()
            ->send();
    }

    public function generateArticleVideoFromEditor(
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $activeBlockId = '',
    ): void {
        try {
            $result = app(ArticleEditorMediaAiService::class)->generateVideo(
                $this->record,
                $selectionText,
                $selectionHtml,
                $userBrief,
                $activeBlockId,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->dispatch('article-ai-media-failed', type: 'video', message: $exception->getMessage());

            Notification::make()
                ->title('Không tạo được video')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->dispatch(
            'article-ai-video-generated',
            url: $result['url'],
            activeBlockId: $activeBlockId,
            seoMediaId: (int) ($result['seo_media_id'] ?? 0),
            status: (string) ($result['status'] ?? 'processing'),
            mediaType: 'video',
        );

        Notification::make()
            ->title('Đang tạo video')
            ->body('Đã chèn placeholder. Video thật sẽ tự cập nhật khi xử lý xong.')
            ->success()
            ->send();
    }

    public function renewArticleFaq(int $index, string $question, string $answer): void
    {
        try {
            $renewed = app(ArticleFaqEditorService::class)->renewFaq(
                $this->record,
                $question,
                $answer,
            );
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Không làm mới được FAQ')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->dispatch(
            'article-faq-renewed',
            index: $index,
            question: $renewed['question'],
            answer: $renewed['answer'],
        );
    }

    /**
     * @return array{duplicate: bool, duplicate_scope: ?string}
     */
    public function checkFaqQuestionDuplicate(string $question, ?int $faqId = null): array
    {
        return app(ArticleFaqEditorService::class)->checkDuplicate(
            $this->record,
            $question,
            $faqId !== null && $faqId > 0 ? $faqId : null,
        );
    }

    public function getEditorOutlineMarkdown(): string
    {
        $this->record->loadMissing('articleMetas');

        /** @var ArticleMeta|null $meta */
        $meta = $this->record->articleMetas->firstWhere('meta_key', 'seo_article_outline');
        if ($meta !== null && is_string($meta->meta_value) && trim($meta->meta_value) !== '') {
            return $meta->meta_value;
        }

        $blocks = $this->record->blocks;
        if (is_array($blocks)) {
            if (is_string($blocks['outline'] ?? null) && trim($blocks['outline']) !== '') {
                return trim($blocks['outline']);
            }
            if (is_string($blocks['markdown'] ?? null) && trim($blocks['markdown']) !== '') {
                return trim($blocks['markdown']);
            }
        }

        return '';
    }

    /**
     * Đổi tên file attachment trên WordPress + thay URL cũ trong mọi bài viết.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function renameAttachmentSlugsOnWordPress(array $items): void
    {
        $result = app(WordPressAttachmentRenameService::class)->renameBatch($this->record, $items);

        $renamed = is_array($result['renamed'] ?? null) ? $result['renamed'] : [];

        if ($result['success']) {
            $this->dispatch('seo-attachment-slugs-rename-finished', success: true, renamed: $renamed, message: $result['message']);

            Notification::make()
                ->title('Đã đổi tên ảnh trên WordPress')
                ->body($result['message'])
                ->success()
                ->send();

            return;
        }

        $this->dispatch('seo-attachment-slugs-rename-finished', success: false, renamed: $renamed, message: $result['message']);

        Notification::make()
            ->title('Không đổi tên được ảnh trên WordPress')
            ->body($result['message'])
            ->danger()
            ->send();
    }

    /** @deprecated Chỉ dùng persistArticleLocal / syncArticleToWordPress từ nút sidebar */
    public function saveContent(string $html, bool $silent = false): void
    {
        if ($silent) {
            $this->persistArticleLocalSilent($html);

            return;
        }

        $this->persistArticleLocal($html);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeArticleMetaJson(string $key): ?array
    {
        /** @var ArticleMeta|null $meta */
        $meta = $this->record->articleMetas->firstWhere('meta_key', $key);
        if ($meta === null || ! is_string($meta->meta_value) || trim($meta->meta_value) === '') {
            return null;
        }

        $decoded = json_decode($meta->meta_value, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    private function syncPublishDatePartsFromRecord(): void
    {
        $dt = $this->resolvePublishAtForEditor() ?? now(config('app.timezone'));

        $this->publishDay = $dt->format('d');
        $this->publishMonth = $dt->format('m');
        $this->publishYear = $dt->format('Y');
        $this->publishHour = $dt->format('H');
        $this->publishMinute = $dt->format('i');
    }

    private function resolvePublishAtForEditor(): ?Carbon
    {
        if ($this->record->published_at instanceof Carbon) {
            return $this->record->published_at->copy()->timezone(config('app.timezone'));
        }

        $fromParts = $this->buildPublishAtFromParts();

        return $fromParts?->timezone(config('app.timezone'));
    }

    private function resolvePublishAtForSave(): ?Carbon
    {
        if ($this->articleStatus === 'draft') {
            return null;
        }

        $candidate = $this->buildPublishAtFromParts();
        if ($candidate !== null) {
            return $candidate->copy()->timezone(config('app.timezone'));
        }

        if ($this->record->published_at instanceof Carbon) {
            return $this->record->published_at->copy()->timezone(config('app.timezone'));
        }

        return now(config('app.timezone'));
    }

    private function buildPublishAtFromParts(): ?Carbon
    {
        $year = (int) trim($this->publishYear);
        $month = (int) trim($this->publishMonth);
        $day = (int) trim($this->publishDay);
        $hour = (int) trim($this->publishHour);
        $minute = (int) trim($this->publishMinute);

        if (
            $year < 1970 || $year > 2100
            || $month < 1 || $month > 12
            || $day < 1 || $day > 31
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
        ) {
            return null;
        }

        try {
            return Carbon::createFromFormat(
                'Y-m-d H:i',
                sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute),
                config('app.timezone'),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatWpScheduleLabel(Carbon $dt): string
    {
        $weekdayMap = [
            0 => 'CN',
            1 => 'Th2',
            2 => 'Th3',
            3 => 'Th4',
            4 => 'Th5',
            5 => 'Th6',
            6 => 'Th7',
        ];

        $weekday = $weekdayMap[(int) $dt->dayOfWeek] ?? 'Th';

        return sprintf('%s %d, %d lúc %02d:%02d', $weekday, (int) $dt->day, (int) $dt->year, (int) $dt->hour, (int) $dt->minute);
    }
}
