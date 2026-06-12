<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Exceptions\FaqManualExtractException;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Services\ArticleContentFaqService;
use App\Addons\SeoContentAi\Services\ArticleCtaPlaceholderService;
use App\Addons\SeoContentAi\Services\ArticleEditorHistoryService;
use App\Addons\SeoContentAi\Services\ArticleEditorHtmlSanitizeService;
use App\Addons\SeoContentAi\Services\ArticleEditorMediaAiService;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoPayloadService;
use App\Addons\SeoContentAi\Services\ArticleFaqBodySyncService;
use App\Addons\SeoContentAi\Services\ArticleFaqEditorService;
use App\Addons\SeoContentAi\Services\ArticleFaqExtractDebugService;
use App\Addons\SeoContentAi\Services\ArticleFaqGeneratorService;
use App\Addons\SeoContentAi\Services\ArticleFaqManualExtractService;
use App\Addons\SeoContentAi\Services\ArticleFaqWordPressImportService;
use App\Addons\SeoContentAi\Services\ArticleFaqWordPressRestoreService;
use App\Addons\SeoContentAi\Services\ArticleGoogleSerpPreviewService;
use App\Addons\SeoContentAi\Services\ArticleMediaLocalService;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use App\Addons\SeoContentAi\Services\ArticleQuickPostReviewService;
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\MediaLibraryArticleResolver;
use App\Addons\SeoContentAi\Services\PromptPostProcessingApplyService;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryService;
use App\Addons\SeoContentAi\Services\SeoProjectApprovalService;
use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use App\Addons\SeoContentAi\Services\TaskWorkflowTestRunner;
use App\Addons\SeoContentAi\Services\VirtualCommentService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Services\WordPressAttachmentMetaUpdateService;
use App\Addons\SeoContentAi\Services\WordPressAttachmentRenameService;
use App\Addons\SeoContentAi\Services\WordPressMediaLibraryService;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\TaskTestContext;
use App\Addons\SeoContentAi\Support\WordPressImageUrl;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.edit-article';

    public string $articleTitle = '';

    public string $articleSlug = '';

    public string $seoTitle = '';

    public string $seoMetaDescription = '';

    public string $seoTitleHydrated = '';

    public string $seoMetaDescriptionHydrated = '';

    public string $focusKeyword = '';

    public string $articleStatus = 'draft';

    public string $visibility = 'public';

    public string $articlePostType = 'article';

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

    /** @var list<array<string, mixed>>|null */
    public ?array $mediaPickerArticleCatalog = null;

    /** @var 'original'|'local'|'article' */
    public string $mediaPickerTab = 'original';

    /** @var 'save'|'sync'|null Thu thập HTML sau khi flush FAQ (Lưu / Đồng bộ WP). */
    public ?string $pendingEditorCollectTarget = null;

    public bool $articleHeavyActionBusy = false;

    /** @var 'save'|'sync'|null */
    public ?string $articleHeavyAction = null;

    public bool $quickReviewsJobPending = false;

    /** @var list<int> Danh mục WordPress đã chọn (term/article id) cho tab Publish. */
    public array $articleCategoryIds = [];

    public string $editorHtml = '';

    public ?int $reviewsCountForEditor = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Filament resolve record qua getEloquentQuery() có eager-load articleMetas
        // bị whitelist 5 key (tối ưu cho trang list). Relation đã "loaded" khiến mọi
        // loadMissing('articleMetas') sau đó bị skip → meta description/gallery trống.
        // Ép load lại ĐẦY ĐỦ metas trước khi hydrate.
        $this->record->load('articleMetas');

        if (! ArticleResource::canContentManagerAccessArticle($this->record)) {
            $this->redirect(
                ArticleResource::getUrl('access-denied', ['record' => $this->record->getKey()]),
                navigate: true,
            );

            return;
        }

        $articleSiteId = (int) ($this->record->site_id ?? 0);
        $globalSiteId = SeoAccessControl::globalSiteId();

        if ($globalSiteId !== null && $articleSiteId > 0 && $globalSiteId !== $articleSiteId) {
            SeoAccessControl::setGlobalSiteId($articleSiteId);
        }

        ArticleResource::syncGlobalSiteForArticle($this->record);
        $this->syncTitleFromWordPressWhenAllowed();
        $this->hydrateArticleState();
        $this->importFaqsFromWordPressOnLoad();
        $this->syncReviewedStatusFromExistingReviews();
    }

    public function updatedArticleSlug($value): void
    {
        $normalized = Str::slug((string) $value);
        if ($this->articleSlug !== $normalized) {
            $this->articleSlug = $normalized;
        }
    }

    public function confirmArticleSlug(?string $slug = null): void
    {
        if ($slug !== null) {
            $this->articleSlug = Str::slug($slug);
        }

        $slug = Str::slug($this->articleSlug);
        if ($slug === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.media_library.invalid_slug'))
                ->warning()
                ->send();

            return;
        }

        $previousSlug = trim((string) ($this->record->slug ?? ''));
        $this->articleSlug = $slug;

        if ($slug === $previousSlug) {
            return;
        }

        $this->record->update(['slug' => $slug]);
        $this->record->refresh();

        if ((int) ($this->record->wp_post_id ?? 0) <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.slug_saved_local'))
                ->body(__('seo-content-ai::filament.article_edit.slug_saved_local_no_wp'))
                ->success()
                ->send();

            return;
        }

        $result = app(WordPressArticleSyncService::class)->syncSlugForArticle($this->record->fresh(), $slug);
        if ($result['success']) {
            $this->refreshArticleSlugFromWordPressAfterSync();

            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.slug_synced'))
                ->body((string) ($result['message'] ?? ''))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_edit.slug_sync_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->warning()
            ->send();
    }

    public function updatedFocusKeyword(): void
    {
        $this->persistFocusKeyword();
        $this->record->refresh();

        $keyword = trim($this->focusKeyword);
        $this->js(sprintf(
            'window.dispatchEvent(new CustomEvent("seo-focus-keyword-updated", { detail: { focus_keyword: %s } }))',
            json_encode($keyword !== '' ? $keyword : null, JSON_THROW_ON_ERROR),
        ));
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
        $this->record->loadCount('faqs');
        $hasLocalFaqs = $this->record->faqs_count > 0;

        if ((int) ($this->record->wp_post_id ?? 0) > 0 && ! $hasLocalFaqs) {
            if (! $this->articleHasStoredWordPressFaqs($this->record)) {
                app(WordPressArticleContentService::class)->fetchFromWordPress($this->record, importFaqs: false);
                $this->record->refresh();
                $this->editorHtml = app(WordPressArticleContentService::class)->resolveEditorHtml($this->record);
            }
        }

        if ($hasLocalFaqs) {
            return;
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
            Actions\Action::make('back_to_articles')
                ->label(__('seo-content-ai::filament.article_list.back_to_articles'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(ArticleResource::getUrl('index')),
            Actions\Action::make('view_content_project_runs')
                ->label('Prompts')
                ->icon('heroicon-o-queue-list')
                ->color('info')
                ->url(fn (): string => ArticleResource::getUrl('prompts', ['record' => $this->record]))
                ->openUrlInNewTab(),
            Actions\Action::make('assign_to_content_project')
                ->label(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                ->icon('heroicon-o-folder-plus')
                ->color('warning')
                ->visible(fn (): bool => ! ArticleResource::articleIsInContentProject($this->record))
                ->form([
                    ArticleResource::assignContentProjectSelectField(
                        fn (): ?int => ArticleResource::resolveArticleSiteId($this->record),
                    ),
                ])
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
                ->modalDescription(__('seo-content-ai::filament.article_list.assign_to_content_project_description'))
                ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
                ->action(function (array $data): void {
                    $summary = ArticleResource::assignArticlesToContentProject(
                        collect([$this->record]),
                        (int) ($data['project_id'] ?? 0),
                    );

                    Notification::make()
                        ->title(__('seo-content-ai::filament.article_list.assign_completed'))
                        ->body(ArticleResource::buildAssignContentProjectBody($summary))
                        ->success()
                        ->send();
                }),
            Actions\Action::make('fetchFromWordPress')
                ->label(__('seo-content-ai::filament.article_list.fetch_from_wordpress'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->visible(fn (): bool => (int) ($this->record->wp_post_id ?? 0) > 0)
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.article_list.fetch_from_wordpress_heading'))
                ->modalDescription(__('seo-content-ai::filament.article_list.fetch_from_wordpress_description'))
                ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.fetch_from_wordpress_submit'))
                ->action(fn (): mixed => $this->restoreArticleFromWordPress()),
            Actions\DeleteAction::make()
                ->icon('heroicon-o-trash')
                ->iconButton()
                ->tooltip('Delete article'),
        ];
    }

    public function restoreArticleFromWordPress(): void
    {
        $restore = app(ArticleFaqWordPressRestoreService::class)->restoreFullArticleFromWordPress($this->record);

        if (! ($restore['restored'] ?? false) || ! filled($restore['editor_html'] ?? null)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.fetch_from_wordpress_failed'))
                ->body((string) ($restore['message'] ?? __('seo-content-ai::filament.article_list.fetch_from_wordpress_failed_body')))
                ->warning()
                ->send();

            return;
        }

        $this->record->refresh();
        app(SeoAnalyzerService::class)->analyze($this->record->fresh());

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.fetch_from_wordpress_success'))
            ->body((string) ($restore['message'] ?? __('seo-content-ai::filament.article_list.fetch_from_wordpress_success_body')))
            ->success()
            ->send();

        $this->finishHeavyArticleActionWithReload(clearLocalState: true);
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
        $this->articlePostType = SeoProjectTask::normalizePostType(ArticlePostTypeResolver::resolve($this->record));
        $this->articleStatus = (string) ($this->record->status ?? 'draft');
        $this->visibility = $this->articleStatus === 'private' ? 'private' : 'public';
        $this->featuredImageUrl = $service->resolveFeaturedImageUrl($this->record);
        $this->productGallery = $this->isProduct()
            ? app(ArticleMediaLocalService::class)->resolveProductAlbum($this->record)
            : [];
        if ($this->supportsProductGallery()) {
            $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        }
        $this->editorHtml = app(ArticleCtaPlaceholderService::class)->highlightBlankPlaceholdersInHtml(
            $service->resolveEditorHtml($this->record),
            (int) ($this->record->site_id ?? 0) > 0 ? (int) $this->record->site_id : null,
        );
        $this->hydrateSeoMetaState();
        $this->loadArticleCategoryIdsFromMeta();
        $this->syncPublishDatePartsFromRecord();
        $this->syncProductGalleryToEditor();
    }

    /** Không đặt tên hydrate{Property} — Livewire sẽ coi là lifecycle hook và gọi từ ngoài. */
    private function loadArticleCategoryIdsFromMeta(): void
    {
        $raw = (string) ($this->record->articleMetas()
            ->where('meta_key', 'category_ids')
            ->value('meta_value') ?? '');

        $decoded = json_decode($raw, true);

        $this->articleCategoryIds = is_array($decoded)
            ? collect($decoded)
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all()
            : [];
    }

    /**
     * Danh mục đã đồng bộ từ WordPress theo site, tách theo taxonomy
     * (post → category, product → product_category) cho tab Publish.
     *
     * @return array{category: list<array{id: int, label: string}>, product_category: list<array{id: int, label: string}>}
     */
    public function getPublishCategoryOptions(): array
    {
        $options = ['category' => [], 'product_category' => []];

        $siteId = (int) ($this->record->site_id ?? 0);
        if ($siteId <= 0) {
            return $options;
        }

        SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereIn('type', ['category', 'product_category'])
            ->orderBy('title')
            ->get(['id', 'title', 'type', 'wp_post_id'])
            ->each(static function (SeoArticle $term) use (&$options): void {
                $type = (string) $term->type;
                $title = trim((string) ($term->title ?? ''));
                $wpId = (int) ($term->wp_post_id ?? 0);

                $options[$type][] = [
                    // Ưu tiên WP term ID để dùng được khi đẩy categories sang WordPress.
                    'id' => $wpId > 0 ? $wpId : (int) $term->id,
                    'label' => $title !== '' ? $title : 'Danh mục #'.$term->id,
                ];
            });

        return $options;
    }

    /**
     * Lưu danh mục đã chọn từ tab Publish (client Alpine) vào article meta.
     *
     * @param  list<int|string>  $categoryIds
     */
    public function applyArticleCategoriesFromClient(array $categoryIds): void
    {
        $this->articleCategoryIds = collect($categoryIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->record->articleMetas()->updateOrCreate(
            ['meta_key' => 'category_ids'],
            ['meta_value' => json_encode($this->articleCategoryIds)],
        );

        $this->skipRender();
    }

    public function isProduct(): bool
    {
        $type = strtolower(trim(SeoProjectTask::normalizePostType($this->articlePostType)));

        return in_array($type, ['product', 'e-commerce'], true);
    }

    public function isTaxonomyArticle(): bool
    {
        return app(WordPressArticleContentService::class)->isTaxonomyRecord($this->record);
    }

    public function supportsProductGallery(): bool
    {
        return $this->isProduct() && ! $this->isTaxonomyArticle();
    }

    public function armEditorBlockMediaPicker(string $blockId): void
    {
        $blockId = trim($blockId);
        if ($blockId === '') {
            return;
        }

        $this->mediaPickerTargetBlockId = $blockId;
        $this->mediaPickerMode = 'editor-block';
    }

    #[On('append-editor-image-to-product-gallery')]
    public function appendEditorImageToProductGallery(
        string $url = '',
        int $wpAttachmentId = 0,
        int $seoMediaId = 0,
        string $slug = '',
        string $alt = '',
    ): void {
        if (! $this->supportsProductGallery()) {
            Notification::make()
                ->title('Không áp dụng album sản phẩm')
                ->body('Chỉ bài sản phẩm WooCommerce mới có album hình ảnh.')
                ->warning()
                ->send();

            return;
        }

        $url = trim($url);
        if ($url === '') {
            Notification::make()
                ->title('Thiếu URL ảnh')
                ->body('Không thể thêm ảnh vào album vì thiếu đường dẫn.')
                ->warning()
                ->send();

            return;
        }

        $wpAttachmentId = max(0, $wpAttachmentId);
        $seoMediaId = max(0, $seoMediaId);
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

        if ($localRefId <= 0) {
            $localRefId = app(ArticleMediaLocalService::class)
                ->resolveLocalRefIdFromImageUrl((int) ($this->record->site_id ?? 0), $url);
        }

        if ($localRefId <= 0) {
            Notification::make()
                ->title('Không thể thêm vào album')
                ->body('Ảnh chưa có ID thư viện (WP hoặc Laravel). Hãy chọn ảnh từ thư viện hoặc đồng bộ WordPress trước.')
                ->warning()
                ->send();

            return;
        }

        $beforeCount = count($this->productGallery);
        $this->productGallery = app(ArticleMediaLocalService::class)
            ->appendProductAlbumLocal($this->record, $localRefId, $url);
        $afterCount = count($this->productGallery);

        if ($afterCount <= $beforeCount) {
            Notification::make()
                ->title('Ảnh đã có trong album')
                ->info()
                ->send();

            return;
        }

        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();

        $this->syncProductGalleryToEditor();

        $this->dispatch(
            'article-media-selected',
            mode: 'gallery',
            url: $url,
            wpAttachmentId: $wpAttachmentId > 0 ? $wpAttachmentId : null,
            seoMediaId: $seoMediaId > 0 ? $seoMediaId : null,
            slug: trim($slug),
            alt: trim($alt),
        );

        Notification::make()
            ->title('Đã thêm vào album sản phẩm')
            ->body('Ảnh đã được append vào Album hình ảnh sản phẩm (lưu cục bộ).')
            ->success()
            ->send();
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
                ->title('WordPress not linked')
                ->body('Sync article from domain before selecting WordPress images.')
                ->warning()
                ->send();

            $this->mediaPickerOpen = false;
            $this->dispatch('close-article-media-modal');

            return;
        }

        if ($mode === 'editor-block') {
            $blockId = trim((string) ($blockId ?? ''));
            if ($blockId === '') {
                $this->mediaPickerOpen = false;

                Notification::make()
                    ->title('Unable to identify image block')
                    ->warning()
                    ->send();

                $this->dispatch('close-article-media-modal');

                return;
            }

            $this->mediaPickerTargetBlockId = $blockId;
            $this->mediaPickerMode = 'editor-block';
            $this->mediaPickerTab = 'article';
            $this->mediaPickerPage = 1;
            $this->mediaPickerError = null;
            $this->mediaPickerSearch = '';
            $this->mediaPickerOpen = true;
            $this->dispatch('open-article-media-modal');

            return;
        } else {
            $this->mediaPickerTargetBlockId = null;
            $this->mediaPickerMode = $mode === 'gallery' ? 'gallery' : 'featured';
        }

        $this->mediaPickerTab = $this->mediaPickerMode === 'editor-block' ? 'article' : 'original';
        $this->mediaPickerPage = 1;
        $this->mediaPickerError = null;
        $this->mediaPickerImages = [];
        $this->mediaPickerArticleCatalog = null;
        $this->mediaPickerSearch = '';
        $this->mediaPickerLoading = true;
        $this->mediaPickerOpen = true;
        $this->dispatch('open-article-media-modal');
        $this->loadMediaPickerImages();
    }

    public function setMediaPickerTab(string $tab): void
    {
        $tab = match ($tab) {
            'local', 'article' => $tab,
            default => 'original',
        };
        if ($this->mediaPickerTab === $tab) {
            return;
        }

        $this->mediaPickerTab = $tab;
        $this->mediaPickerPage = 1;
        $this->mediaPickerSearch = '';

        if ($tab === 'article') {
            return;
        }

        $this->mediaPickerArticleCatalog = null;
        $this->dispatch('article-media-picker-loading');
        $this->loadMediaPickerImages();
    }

    public function closeMediaPicker(): void
    {
        $this->mediaPickerOpen = false;
        $this->mediaPickerTargetBlockId = null;
    }

    public function searchMediaPicker(string $query): void
    {
        if ($this->mediaPickerTab === 'article') {
            return;
        }

        $this->mediaPickerSearch = trim($query);
        $this->mediaPickerPage = 1;
        $this->dispatch('article-media-picker-loading');
        $this->loadMediaPickerImages();
    }

    public function mediaPickerPreviousPage(): void
    {
        if ($this->mediaPickerPage <= 1) {
            return;
        }

        $this->goToMediaPickerPage($this->mediaPickerPage - 1);
    }

    public function mediaPickerNextPage(): void
    {
        if ($this->mediaPickerPage >= $this->mediaPickerTotalPages) {
            return;
        }

        $this->goToMediaPickerPage($this->mediaPickerPage + 1);
    }

    public function loadMediaPickerImages(): void
    {
        $this->mediaPickerLoading = true;
        $this->dispatch('article-media-picker-loading');
        $this->mediaPickerError = null;

        $this->record->loadMissing('site');
        $site = $this->record->site;
        if ($site === null) {
            $this->mediaPickerError = 'Domain not found.';
            $this->mediaPickerLoading = false;

            return;
        }

        $search = trim($this->mediaPickerSearch);
        $articleId = (int) $this->record->id;
        $library = app(SeoMediaLibraryService::class);

        if ($this->mediaPickerTab === 'article') {
            $postImagesResult = app(ArticlePostImagesService::class)->fetchForMediaPicker(
                $this->record,
                1,
                null,
                96,
            );
            $postImages = is_array($postImagesResult['images'] ?? null) ? $postImagesResult['images'] : [];
            $supplementalImages = $this->getEditorSupplementalImagesPayload();

            $seen = [];
            $merged = [];
            $append = static function (array $row) use (&$merged, &$seen): void {
                $src = trim((string) ($row['url'] ?? $row['src'] ?? ''));
                if ($src === '') {
                    return;
                }

                $wpId = (int) ($row['wp_attachment_id'] ?? 0);
                $seoId = (int) ($row['seo_media_id'] ?? 0);
                $identity = $wpId > 0
                    ? 'wp:'.$wpId
                    : ($seoId > 0 ? 'seo:'.$seoId : 'src:'.mb_strtolower($src));
                if (isset($seen[$identity])) {
                    return;
                }

                $seen[$identity] = true;
                $merged[] = [
                    'id' => (int) ($row['id'] ?? ($wpId > 0 ? $wpId : ($seoId > 0 ? $seoId : count($merged) + 1))),
                    'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                    'seo_media_id' => $seoId > 0 ? $seoId : null,
                    'url' => $src,
                    'thumb_url' => trim((string) ($row['thumb_url'] ?? $src)),
                    'media_type' => (string) ($row['media_type'] ?? 'image'),
                    'alt' => trim((string) ($row['alt'] ?? '')),
                    'slug' => trim((string) ($row['slug'] ?? '')),
                ];
            };

            foreach ($postImages as $row) {
                $append($row);
            }
            foreach ($supplementalImages as $row) {
                $append($row);
            }

            $this->mediaPickerArticleCatalog = $merged;
            $this->applyArticleCatalogPickerPage();

            $result = [
                'images' => $this->mediaPickerImages,
                'total_pages' => $this->mediaPickerTotalPages,
                'page' => $this->mediaPickerPage,
                'error' => null,
            ];
        } else {
            if ($this->mediaPickerTab === 'local') {
                $library->assignRecentOrphanMediaToArticle($site, $articleId);
            }

            $result = $this->mediaPickerTab === 'local'
                ? $library->fetch(
                    $site,
                    null,
                    $this->mediaPickerPage,
                    $search !== '' ? $search : null,
                    24,
                    $articleId,
                )
                : app(WordPressMediaLibraryService::class)->fetch(
                    $site,
                    null,
                    $this->mediaPickerPage,
                    24,
                    $search !== '' ? $search : null,
                );

            $images = is_array($result['images'] ?? null) ? $result['images'] : [];
            $this->mediaPickerImages = $this->mediaPickerTab === 'local'
                ? app(MediaLibraryArticleResolver::class)->enrichImages((int) $site->id, $images)
                : $images;
        }
        $this->mediaPickerTotalPages = max(1, (int) ($result['total_pages'] ?? 1));
        $this->mediaPickerPage = max(1, (int) ($result['page'] ?? $this->mediaPickerPage));
        $this->mediaPickerError = filled($result['error'] ?? null) ? (string) $result['error'] : null;
        $this->mediaPickerLoading = false;
        $this->broadcastMediaPickerToClient();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeMediaPickerImagesForClient(array $images): array
    {
        $tab = (string) $this->mediaPickerTab;

        return array_values(array_map(function (array $image) use ($tab): array {
            $wpId = (int) ($image['wp_attachment_id'] ?? ($tab === 'original' ? ($image['id'] ?? 0) : 0));
            $seoId = (int) ($image['seo_media_id'] ?? ($tab === 'local' ? ($image['id'] ?? 0) : 0));
            $url = trim((string) ($image['url'] ?? ''));
            $thumbUrl = trim((string) ($image['thumb_url'] ?? $url));
            $pickerKey = $tab.'-'.($seoId > 0 ? 'seo-'.$seoId : 'wp-'.$wpId).'-'.md5($url);

            return [
                'picker_key' => $pickerKey,
                'id' => (int) ($image['id'] ?? ($wpId > 0 ? $wpId : ($seoId > 0 ? $seoId : 0))),
                'wp_attachment_id' => $wpId,
                'seo_media_id' => $seoId,
                'url' => $url,
                'thumb_url' => $thumbUrl !== '' ? $thumbUrl : $url,
                'slug' => trim((string) ($image['slug'] ?? '')),
                'alt' => trim((string) ($image['alt'] ?? '')),
                'media_type' => strtolower(trim((string) ($image['media_type'] ?? 'image'))) === 'video' ? 'video' : 'image',
            ];
        }, $images));
    }

    private function broadcastMediaPickerToClient(): void
    {
        $catalog = $this->mediaPickerTab === 'article' && $this->mediaPickerArticleCatalog !== null
            ? $this->normalizeMediaPickerImagesForClient($this->mediaPickerArticleCatalog)
            : null;

        $this->dispatch(
            'article-media-picker-loaded',
            images: $this->normalizeMediaPickerImagesForClient($this->mediaPickerImages),
            catalog: $catalog,
            page: $this->mediaPickerPage,
            totalPages: $this->mediaPickerTotalPages,
            error: $this->mediaPickerError,
            tab: $this->mediaPickerTab,
        );
    }

    private function applyArticleCatalogPickerPage(): void
    {
        $catalog = $this->mediaPickerArticleCatalog ?? [];
        $search = trim($this->mediaPickerSearch);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $catalog = array_values(array_filter($catalog, static function (array $row) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($row['slug'] ?? ''),
                    (string) ($row['alt'] ?? ''),
                    (string) ($row['url'] ?? ''),
                ])));

                return str_contains($haystack, $needle);
            }));
        }

        $perPage = 24;
        $total = count($catalog);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $this->mediaPickerPage), $totalPages);
        $offset = ($page - 1) * $perPage;

        $this->mediaPickerTotalPages = $totalPages;
        $this->mediaPickerPage = $page;
        $this->mediaPickerImages = array_slice($catalog, $offset, $perPage);
    }

    public function goToMediaPickerPage(int $page): void
    {
        $page = max(1, $page);
        if ($page > $this->mediaPickerTotalPages) {
            return;
        }

        $this->mediaPickerPage = $page;

        if ($this->mediaPickerTab === 'article' && $this->mediaPickerArticleCatalog !== null) {
            $this->applyArticleCatalogPickerPage();
            $this->broadcastMediaPickerToClient();

            return;
        }

        $this->dispatch('article-media-picker-loading');
        $this->loadMediaPickerImages();
    }

    public function reloadMediaPickerImages(): void
    {
        $this->loadMediaPickerImages();
    }

    public function selectMediaFromPicker(
        int $wpAttachmentId,
        string $url,
        string $alt = '',
        string $slug = '',
        int $seoMediaId = 0,
        string $mediaType = 'image',
        ?string $pickerMode = null,
        ?string $pickerTab = null,
        ?string $targetBlockId = null,
    ): void {
        $url = WordPressImageUrl::toFullSize(trim($url));
        if ($url === '') {
            return;
        }

        $resolvedMode = in_array($pickerMode, ['featured', 'gallery', 'editor-block'], true)
            ? $pickerMode
            : $this->mediaPickerMode;
        $resolvedTab = in_array($pickerTab, ['article', 'original', 'local'], true)
            ? $pickerTab
            : $this->mediaPickerTab;

        $slug = trim($slug);
        if ($slug === '' || WordPressImageUrl::isScaledVariant($url)) {
            $slug = WordPressImageUrl::slugFromUrl($url);
        }

        $seoMediaId = max(0, $seoMediaId);
        $wpAttachmentId = max(0, $wpAttachmentId);
        $mediaType = strtolower(trim($mediaType)) === 'video' ? 'video' : 'image';
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

        if ($resolvedMode === 'editor-block') {
            if ($resolvedTab === 'local' && $seoMediaId <= 0 && $wpAttachmentId <= 0) {
                return;
            }

            if ($resolvedTab === 'original' && $wpAttachmentId <= 0) {
                return;
            }

            $blockId = trim((string) ($targetBlockId ?? $this->mediaPickerTargetBlockId ?? ''));
            if ($blockId === '') {
                return;
            }

            $this->dispatch(
                'editor-block-image-selected',
                blockId: $blockId,
                attachmentId: $wpAttachmentId,
                seoMediaId: $seoMediaId,
                mediaType: $mediaType,
                url: $url,
                alt: trim($alt),
                slug: trim($slug),
            );

            $this->mediaPickerTargetBlockId = null;

            Notification::make()
                ->title('Image selected for block')
                ->success()
                ->send();

            return;
        }

        if ($localRefId <= 0) {
            return;
        }

        if ($resolvedMode === 'gallery') {
            return;
        }

        if ($mediaType !== 'image') {
            Notification::make()
                ->title('Featured image only supports image files')
                ->warning()
                ->send();

            return;
        }

        $localMedia = app(ArticleMediaLocalService::class);

        $localMedia->applyFeaturedLocal($this->record, $localRefId, $url);
        $this->featuredImageUrl = trim($url);
        $title = 'Featured image selected (saved locally)';

        $this->record->refresh();
        $this->syncProductGalleryToEditor();
        $this->dispatch(
            'article-media-selected',
            mode: $resolvedMode,
            url: $url,
            wpAttachmentId: $wpAttachmentId > 0 ? $wpAttachmentId : null,
            seoMediaId: $seoMediaId > 0 ? $seoMediaId : null,
            slug: trim($slug),
            alt: trim($alt),
        );

        $this->dispatch('close-article-media-modal');

        Notification::make()
            ->title($title)
            ->body('Click "Sync" to upload images to WordPress.')
            ->success()
            ->send();
    }

    /**
     * @param  array{items?: list<array<string, mixed>>|list<array<string, mixed>>}  $payload
     */
    public function confirmGallerySelectionFromPicker(array $payload): void
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : $payload;
        $items = array_values(array_filter($items, static fn (mixed $row): bool => is_array($row)));
        if (! $this->supportsProductGallery()) {
            Notification::make()
                ->title('Album not applicable')
                ->body('Category only supports featured image.')
                ->warning()
                ->send();

            return;
        }

        if ($items === []) {
            Notification::make()
                ->title('Chưa chọn ảnh')
                ->body('Hãy chọn ít nhất một ảnh trước khi thêm vào album.')
                ->warning()
                ->send();

            return;
        }

        $localMedia = app(ArticleMediaLocalService::class);
        $added = 0;
        $skipped = 0;

        $album = $this->productGallery;
        if ($album === []) {
            $this->record->unsetRelation('articleMetas');
            $album = $localMedia->resolveProductAlbum($this->record);
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
            $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? 0));
            $mediaType = strtolower(trim((string) ($item['media_type'] ?? $item['mediaType'] ?? 'image')));
            if ($mediaType !== '' && $mediaType !== 'image') {
                $skipped++;

                continue;
            }
            $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

            if ($localRefId <= 0) {
                $localRefId = $localMedia->resolveLocalRefIdFromImageUrl((int) ($this->record->site_id ?? 0), $url);
            }

            if ($localRefId <= 0) {
                $skipped++;

                continue;
            }

            $duplicate = collect($album)->contains(
                static fn (array $row): bool => ((int) ($row['id'] ?? 0) > 0 && (int) ($row['id'] ?? 0) === $localRefId)
                    || (string) ($row['url'] ?? '') === $url
            );
            if ($duplicate) {
                $skipped++;

                continue;
            }

            $album[] = [
                'id' => $localRefId,
                'url' => $url,
            ];
            $added++;
        }

        if ($added > 0) {
            $this->productGallery = $localMedia->saveProductAlbumLocal($this->record, $album);
        }

        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();
        $this->syncProductGalleryToEditor();

        $this->mediaPickerOpen = false;
        $this->dispatch('close-article-media-modal');

        if ($added <= 0) {
            Notification::make()
                ->title('Không thêm được ảnh mới')
                ->body($skipped > 0
                    ? 'Các ảnh đã chọn có thể đã có trong album hoặc thiếu ID thư viện.'
                    : 'Không có ảnh hợp lệ để thêm.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Đã thêm vào album sản phẩm')
            ->body(sprintf(
                'Đã thêm %d ảnh vào album%s.',
                $added,
                $skipped > 0 ? " ({$skipped} ảnh bỏ qua)" : '',
            ))
            ->success()
            ->send();
    }

    public function removeProductGalleryImage(string $url): void
    {
        if (! $this->supportsProductGallery()) {
            return;
        }

        $this->productGallery = app(ArticleMediaLocalService::class)
            ->removeProductAlbumItemByUrl($this->record, $url);
        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();
        $this->syncProductGalleryToEditor();
        $this->dispatch('article-media-removed', mode: 'gallery', url: trim($url));

        Notification::make()
            ->title('Image removed from album')
            ->success()
            ->send();
    }

    /**
     * @param  list<string>  $orderedUrls
     */
    public function reorderProductGallery(array $orderedUrls = []): void
    {
        if (! $this->supportsProductGallery()) {
            return;
        }

        $this->productGallery = app(ArticleMediaLocalService::class)
            ->reorderProductAlbumLocal($this->record, $orderedUrls);
        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();
        $this->syncProductGalleryToEditor();
    }

    private function syncProductGalleryToEditor(): void
    {
        if (! $this->supportsProductGallery()) {
            return;
        }

        $this->dispatch(
            'seo-product-gallery-updated',
            gallery: $this->productGallery,
            article_id: (int) $this->record->id,
        );
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
        $slug = Str::slug($this->articleSlug !== '' ? $this->articleSlug : $this->articleTitle);

        return $slug !== '' ? $slug : 'sample-post';
    }

    public function getPermalinkSuffix(): string
    {
        $permalink = trim($this->getDisplayPermalink());
        if ($permalink === '') {
            return '';
        }

        $slug = trim($this->articleSlug !== '' ? $this->articleSlug : (string) ($this->record->slug ?? ''));
        if ($slug === '') {
            return '';
        }

        $path = (string) parse_url($permalink, PHP_URL_PATH);
        $basename = trim((string) basename($path));
        if ($basename === '') {
            return '';
        }

        $prefix = $slug.'.';
        if (str_starts_with($basename, $prefix)) {
            return substr($basename, strlen($slug));
        }

        return '';
    }

    public function getDisplayPermalink(): string
    {
        if ((int) ($this->record->wp_post_id ?? 0) > 0) {
            return $this->getArticlePermalink();
        }

        $preview = app(\App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder::class)
            ->preview($this->record, $this->getDisplaySlug());
        if ($preview !== '') {
            return $preview;
        }

        $base = $this->getPermalinkBase();

        return $base !== ''
            ? rtrim($base, '/').'/'.$this->getDisplaySlug()
            : '';
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     url: string,
     *     description: string,
     *     display_url: string,
     *     meta: array<string, mixed>
     * }
     */
    public function getGoogleSerpPreview(): array
    {
        return app(ArticleGoogleSerpPreviewService::class)->buildForArticle(
            $this->record,
            trim($this->seoTitle) !== '' ? trim($this->seoTitle) : trim($this->articleTitle),
            trim($this->seoMetaDescription),
            $this->getDisplayPermalink(),
        );
    }

    public function getArticlePermalink(): string
    {
        if ((int) ($this->record->wp_post_id ?? 0) <= 0) {
            return '';
        }

        return app(WordPressArticleContentService::class)
            ->resolveStoredWordPressPermalink($this->record);
    }

    public function getStatusLabel(): string
    {
        return match ($this->articleStatus) {
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
            default => 'Draft',
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
            return 'Not scheduled';
        }

        return $this->formatWpScheduleLabel($publishedAt);
    }

    public function getVisibilityLabel(): string
    {
        return $this->visibility === 'private' ? 'Private' : 'Public';
    }

    public function getStatusLabelForPublishBox(): string
    {
        return match ($this->articleStatus) {
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
            default => 'Draft',
        };
    }

    public function applyPublishBoxFromClient(
        string $postType,
        string $status,
        string $visibility,
        string $publishDay,
        string $publishMonth,
        string $publishYear,
        string $publishHour,
        string $publishMinute,
    ): void {
        $this->articlePostType = SeoProjectTask::normalizePostType($postType);
        $this->articleStatus = in_array($status, ['draft', 'published', 'scheduled', 'private'], true)
            ? $status
            : 'draft';
        $this->visibility = $visibility === 'private' ? 'private' : 'public';
        $this->publishDay = $publishDay;
        $this->publishMonth = $publishMonth;
        $this->publishYear = $publishYear;
        $this->publishHour = $publishHour;
        $this->publishMinute = $publishMinute;

        if ($this->supportsProductGallery()) {
            $this->productGallery = app(ArticleMediaLocalService::class)->resolveProductAlbum($this->record);
            $this->featuredImageUrl = $this->productGallery[0]['url'] ?? $this->featuredImageUrl;
        } else {
            $this->productGallery = [];
        }

        $this->skipRender();
    }

    /**
     * @param  list<array{id?: int, url?: string, wp_attachment_id?: int, seo_media_id?: int}>  $items
     * @return list<array{id: int, url: string}>
     */
    public function persistProductAlbumFromClient(array $items): array
    {
        if (! $this->supportsProductGallery()) {
            $this->skipRender();

            return [];
        }

        $localMedia = app(ArticleMediaLocalService::class);
        $siteId = (int) ($this->record->site_id ?? 0);
        $album = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
            $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? $item['id'] ?? 0));
            $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

            if ($localRefId <= 0) {
                $localRefId = $localMedia->resolveLocalRefIdFromImageUrl($siteId, $url);
            }

            $album[] = [
                'id' => $localRefId,
                'url' => $url,
            ];
        }

        $this->productGallery = $localMedia->saveProductAlbumLocal($this->record, $album);
        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? $this->featuredImageUrl;
        $this->record->refresh();

        $this->skipRender();

        return $this->productGallery;
    }

    /**
     * @param  array{url?: string, wp_attachment_id?: int, wpAttachmentId?: int, seo_media_id?: int, seoMediaId?: int}  $item
     */
    public function persistFeaturedImageFromClient(array $item): void
    {
        if ($this->supportsProductGallery()) {
            $this->skipRender();

            return;
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            $this->skipRender();

            return;
        }

        $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
        $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? 0));
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;
        $localMedia = app(ArticleMediaLocalService::class);

        if ($localRefId <= 0) {
            $localRefId = $localMedia->resolveLocalRefIdFromImageUrl(
                (int) ($this->record->site_id ?? 0),
                $url,
            );
        }

        if ($localRefId > 0) {
            $localMedia->applyFeaturedLocal($this->record, $localRefId, $url);
            $this->featuredImageUrl = $url;
            $this->record->refresh();
        }

        $this->skipRender();
    }

    public function requestSaveArticle(): void
    {
        if ($this->articleHeavyActionBusy) {
            return;
        }

        $this->beginHeavyArticleAction('save');
        $this->pendingEditorCollectTarget = 'save';
        $this->dispatch('flush-article-faqs');
    }

    public function requestSyncToWordPress(): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        if ($this->articleHeavyActionBusy) {
            return;
        }

        $this->beginHeavyArticleAction('sync');
        $this->pendingEditorCollectTarget = 'sync';
        $this->dispatch('flush-article-faqs');
    }

    private function beginHeavyArticleAction(string $action): void
    {
        $this->articleHeavyActionBusy = true;
        $this->articleHeavyAction = $action;
        $this->dispatch('article-autosave-lock', reason: 'article-heavy-action', locked: true);
        $this->dispatch('article-wordpress-sync-lock', action: $action);
    }

    private function finishHeavyArticleActionWithReload(bool $clearLocalState = false): void
    {
        if ($clearLocalState) {
            $articleId = (int) $this->record->getKey();
            $siteId = (int) ($this->record->site_id ?? 0);
            $this->js(
                "window.__seoClearArticleLocalState?.({$articleId}, {$siteId}); window.location.reload();"
            );

            return;
        }

        $this->js('window.location.reload()');
    }

    private function cancelHeavyArticleAction(): void
    {
        $this->articleHeavyActionBusy = false;
        $this->articleHeavyAction = null;
        $this->dispatch('article-autosave-lock', reason: 'article-heavy-action', locked: false);
        $this->dispatch('article-wordpress-sync-unlock');
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

    public function getReviewStatusLabel(): string
    {
        return (bool) $this->record->is_reviewed
            ? __('seo-content-ai::filament.article_list.reviewed')
            : __('seo-content-ai::filament.article_list.not_reviewed');
    }

    public function canToggleArticleReview(): bool
    {
        if (SeoAccessControl::canAccessManagerFeatures()) {
            return true;
        }

        if (SeoAccessControl::isContentManager()) {
            return ArticleResource::articleIsInContentProject($this->record);
        }

        return false;
    }

    public function getReviewedAtLabel(): ?string
    {
        $reviewedAt = $this->record->reviewed_at;

        return $reviewedAt instanceof Carbon
            ? $reviewedAt->timezone(config('app.timezone'))->format('d/m/Y H:i')
            : null;
    }

    public function getVirtualCommentsCount(): int
    {
        return count(app(VirtualCommentService::class)->getFromArticle($this->record));
    }

    public function getReviewsCountForEditor(): int
    {
        if ($this->reviewsCountForEditor !== null) {
            return max(0, (int) $this->reviewsCountForEditor);
        }

        $this->reviewsCountForEditor = count($this->getVirtualReviewsPayload());

        return $this->reviewsCountForEditor;
    }

    public function canGenerateQuickPostReviews(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->getPostReviewTaskId() !== null;
    }

    public function shouldShowQuickCreateReviewsButton(): bool
    {
        return $this->canGenerateQuickPostReviews() && $this->getReviewsCountForEditor() === 0;
    }

    public function generateQuickPostReviews(): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        if (! $this->canGenerateQuickPostReviews()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body(__('seo-content-ai::filament.article_list.quick_create_reviews_configure_hint'))
                ->warning()
                ->send();

            return;
        }

        if ($this->getReviewsCountForEditor() > 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body(__('seo-content-ai::filament.article_list.quick_create_reviews_already_exists'))
                ->warning()
                ->send();

            return;
        }

        $this->quickReviewsJobPending = true;

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }

            $result = app(ArticleQuickPostReviewService::class)->runForArticle($this->record->fresh());
            $this->applyQuickPostReviewsResult($result);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->quickReviewsJobPending = false;
        }
    }

    /**
     * @param  array{success?: bool, message?: string, created_count?: int|null}  $result
     */
    private function applyQuickPostReviewsResult(array $result): void
    {
        $this->record->refresh();

        $reviews = $this->getVirtualReviewsPayload();
        if ($reviews !== []) {
            $this->dispatch('virtual-reviews-updated', reviews: $reviews);
        }

        $success = (bool) ($result['success'] ?? false);
        $message = trim((string) ($result['message'] ?? ''));

        $notification = Notification::make()
            ->title(
                $success
                    ? __('seo-content-ai::filament.article_list.quick_create_reviews_success')
                    : __('seo-content-ai::filament.article_list.quick_create_reviews_failed'),
            )
            ->body(
                $message !== ''
                    ? $message
                    : ($success
                        ? __('seo-content-ai::filament.article_list.virtual_comments_count', ['count' => count($reviews)])
                        : ''),
            );

        if ($success) {
            if ($message !== '' && str_contains($message, 'Đồng bộ WordPress thất bại')) {
                $notification->warning();
            } else {
                $notification->success();
            }
        } else {
            $notification->danger();
        }

        $notification->send();
    }

    public function syncVirtualReviewsToWordPress(): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        if ((int) ($this->record->wp_post_id ?? 0) <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.virtual_comments_sync_failed'))
                ->body('Bài viết chưa có WordPress Post ID.')
                ->warning()
                ->send();

            return;
        }

        if ($this->getVirtualCommentsCount() === 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.virtual_comments_sync_failed'))
                ->body(__('seo-content-ai::filament.article_list.reviews_tab_empty'))
                ->warning()
                ->send();

            return;
        }

        $result = app(VirtualCommentService::class)->syncToWordPress($this->record->fresh());

        if ($result['success'] ?? false) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.virtual_comments_sync_success'))
                ->body((string) ($result['message'] ?? ''))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.virtual_comments_sync_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->danger()
            ->send();
    }

    /**
     * @return list<array{author: string, content: string, rating?: int|null, date: string}>
     */
    #[On('request-virtual-reviews-refresh')]
    public function refreshVirtualReviewsForEditor(): array
    {
        $this->record->refresh();
        $reviews = $this->getVirtualReviewsPayload();
        $this->reviewsCountForEditor = count($reviews);
        $this->syncReviewedStatusFromExistingReviews($reviews);
        $this->dispatch('virtual-reviews-updated', reviews: $reviews);

        return $reviews;
    }

    public function pollQuickReviewsJob(): void
    {
        if (! $this->quickReviewsJobPending) {
            return;
        }

        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            return;
        }

        $cacheKey = 'seo_article_reviews_ready:'.(int) $this->record->id.':'.$userId;
        if (! Cache::pull($cacheKey)) {
            return;
        }

        $this->quickReviewsJobPending = false;
        $this->record->refresh();

        $reviews = $this->getVirtualReviewsPayload();
        $this->dispatch('virtual-reviews-updated', reviews: $reviews);

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_success'))
            ->body(__('seo-content-ai::filament.article_list.virtual_comments_count', [
                'count' => count($reviews),
            ]))
            ->success()
            ->send();
    }

    /**
     * @return list<array{author: string, content: string, rating?: int|null, date: string}>
     */
    public function getVirtualReviewsPayload(): array
    {
        return app(VirtualCommentService::class)->getForEditor($this->record);
    }

    /**
     * @param  list<array{author: string, content: string, rating?: int|null, date: string}>|null  $reviews
     */
    private function syncReviewedStatusFromExistingReviews(?array $reviews = null): void
    {
        $rows = $reviews ?? $this->getVirtualReviewsPayload();
        if (count($rows) <= 0) {
            return;
        }

        if ((bool) $this->record->is_reviewed) {
            return;
        }

        $this->record->update([
            'is_reviewed' => true,
            'reviewed_at' => $this->record->reviewed_at ?? now(),
        ]);
        $this->record->refresh();
    }

    public function toggleArticleReview(): void
    {
        if (! $this->canToggleArticleReview()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.review_toggle_denied'))
                ->warning()
                ->send();

            return;
        }

        if ((bool) $this->record->is_reviewed) {
            ArticleResource::markArticleUnreviewed($this->record);
            $this->record->refresh();

            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.not_reviewed'))
                ->success()
                ->send();

            return;
        }

        $this->approveArticle();
    }

    public function approveArticle(): void
    {
        if ((bool) $this->record->is_reviewed) {
            return;
        }

        if (SeoAccessControl::isContentManager()) {
            app(SeoProjectApprovalService::class)->approveLinkedProject(
                $this->record,
                auth()->user(),
            );
        }

        $deletedCount = ArticleResource::markArticleReviewed($this->record);

        Notification::make()
            ->title(SeoAccessControl::isContentManager()
                ? 'Project đã được đánh dấu Đã duyệt'
                : __('seo-content-ai::filament.article_list.article_reviewed'))
            ->body(__('seo-content-ai::filament.article_list.deleted_local_images', ['count' => $deletedCount]))
            ->success()
            ->send();

        $this->runProductReviewWorkflowAfterApproval();

        $this->record->refresh();
    }

    private function runProductReviewWorkflowAfterApproval(): void
    {
        $article = $this->record->fresh();
        if (! $article instanceof SeoArticle
            || ArticlePostTypeResolver::resolve($article) !== SeoProjectTask::POST_TYPE_PRODUCT
            || $this->getVirtualCommentsCount() > 0
            || ! $this->canGenerateQuickPostReviews()) {
            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $result = app(ArticleQuickPostReviewService::class)->runForArticle($article);
            $this->applyQuickPostReviewsResult($result);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body($exception->getMessage())
                ->warning()
                ->send();
        }
    }

    /**
     * Fetch slug + permalink mới từ WordPress sau khi đồng bộ slug.
     */
    private function refreshArticleSlugFromWordPressAfterSync(): void
    {
        $refresh = app(WordPressArticleContentService::class)
            ->refreshSlugAndPermalinkFromWordPress($this->record->fresh());

        if ($refresh['slug'] !== '') {
            $this->articleSlug = (string) $refresh['slug'];
        }

        $this->record->refresh();
        $this->record->loadMissing('articleMetas');

        $seoResult = app(SeoAnalyzerService::class)->analyzePreview(
            $this->record,
            trim((string) ($this->record->body ?? $this->editorHtml)),
            trim($this->seoTitle) !== '' ? trim($this->seoTitle) : trim($this->articleTitle),
            $this->articleSlug !== '' ? $this->articleSlug : trim((string) ($this->record->slug ?? '')),
            trim($this->seoMetaDescription) !== '' ? trim($this->seoMetaDescription) : null,
        );
        $this->dispatch('seo-analyze-result', result: $seoResult);
    }

    /**
     * Cập nhật editor + meta ảnh sau khi body đã được thay URL WordPress.
     */
    private function refreshEditorAfterWordPressSync(): void
    {
        $this->record->refresh();
        $this->record->loadMissing('articleMetas');

        $syncedHtml = trim((string) ($this->record->body ?? ''));
        if ($syncedHtml !== '') {
            app(ArticlePostImagesService::class)->syncFromHtml($this->record, $syncedHtml);
            $this->editorHtml = $syncedHtml;
        }

        $this->featuredImageUrl = app(WordPressArticleContentService::class)->resolveFeaturedImageUrl($this->record);
        $this->productGallery = $this->supportsProductGallery()
            ? app(ArticleMediaLocalService::class)->resolveProductAlbum($this->record)
            : [];
        if ($this->supportsProductGallery()) {
            $this->featuredImageUrl = $this->productGallery[0]['url'] ?? $this->featuredImageUrl;
        }

        $this->dispatch(
            'article-faqs-extracted',
            faqs: app(ArticleFaqEditorService::class)->payloadForArticle($this->record),
            editorHtml: $syncedHtml !== '' ? $syncedHtml : $this->editorHtml,
        );
        $this->dispatch('article-post-images-synced', images: $this->getEditorImagesPayload());
        $this->dispatch('article-supplemental-images-synced', images: $this->getEditorSupplementalImagesPayload());
    }

    /**
     * Lưu vào Laravel (không đẩy WordPress).
     */
    public function persistArticleLocal(string $html): void
    {
        try {
            $html = app(ArticleEditorHtmlSanitizeService::class)->stripTransientEditorMarkup($html);

            $faqSync = app(ArticleFaqBodySyncService::class)->extractFromBodyWhenMissing($this->record, $html);
            $html = $faqSync['body_html'];
            if ($faqSync['extracted']) {
                $this->dispatch('article-faqs-extracted', faqs: $faqSync['faqs'], editorHtml: $html);
            } else {
                $this->dispatchFaqExtractDebugIfPresent($faqSync['extract_debug'] ?? null);
            }

            $slug = Str::slug($this->articleSlug);
            $seoTitle = trim($this->seoTitle) !== '' ? trim($this->seoTitle) : trim($this->articleTitle);
            $seoMetaDescription = trim($this->seoMetaDescription);

            $publishAt = $this->resolvePublishAtForSave();
            $postType = SeoProjectTask::normalizePostType($this->articlePostType);

            $this->record->update([
                'title' => trim($this->articleTitle),
                'slug' => $slug !== '' ? $slug : null,
                'type' => $postType,
                'status' => $this->articleStatus,
                'published_at' => $publishAt,
                'body' => $html,
                'user_id' => auth()->id(),
            ]);
            $this->persistArticlePostTypeMeta($postType);
            $this->articlePostType = $postType;
            $this->persistSeoMetaFields();

            $this->articleSlug = $slug;
            $this->syncPublishDatePartsFromRecord();

            app(ArticlePostImagesService::class)->syncFromHtml($this->record, $html);
            $this->record->refresh();
            app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($this->record);

            $seoResult = app(SeoAnalyzerService::class)->analyzeSubmittedContent(
                $this->record->fresh(),
                $html,
                $seoTitle,
                $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
                $seoMetaDescription !== '' ? $seoMetaDescription : null,
            );
            $this->dispatch('seo-analyze-result', result: $seoResult);

            $saveBody = 'Content is saved only in SEO system. Use "Sync" to push to WordPress.';
            if ($faqSync['extracted']) {
                $saveBody = 'Extracted '.$faqSync['faq_count'].' FAQ items from content into FAQ panel. '.$saveBody;
            } elseif (! empty($faqSync['extract_debug'])) {
                $saveBody = 'FAQ heading exists but questions/answers were not extracted - check FAQ debug block. '.$saveBody;
            }

            Notification::make()
                ->title('Article saved')
                ->body($saveBody)
                ->success()
                ->send();

            if ($this->articleHeavyActionBusy) {
                $this->finishHeavyArticleActionWithReload(clearLocalState: true);
            }
        } catch (\Throwable $exception) {
            $this->cancelHeavyArticleAction();

            throw $exception;
        }
    }

    /**
     * Lưu Laravel rồi đẩy lên WordPress.
     */
    public function syncArticleToWordPress(string $html): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        try {
            $html = $this->persistArticleLocalSilent($html, syncVirtualCommentsToWordPress: false);

            $slug = Str::slug($this->articleSlug);
            app(SeoAnalyzerService::class)->analyzeSubmittedContent(
                $this->record->fresh(),
                $html,
                trim($this->seoTitle) !== '' ? trim($this->seoTitle) : trim($this->articleTitle),
                $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
                trim($this->seoMetaDescription) !== '' ? trim($this->seoMetaDescription) : null,
            );

            $syncService = app(WordPressArticleSyncService::class);
            $article = $this->record->fresh();

            if ((int) ($article->wp_post_id ?? 0) <= 0) {
                $created = $syncService->createForArticle($article);
                if (! ($created['success'] ?? false)) {
                    Notification::make()
                        ->title('Không thể đăng bài mới lên WordPress')
                        ->body((string) ($created['message'] ?? 'WordPress không tạo được bài viết.'))
                        ->danger()
                        ->send();

                    $this->cancelHeavyArticleAction();

                    return;
                }

                $article = $article->fresh();
            }

            $result = $syncService->syncForArticle(
                $article,
                $this->resolveLivewireSeoPayloadForWordPress(),
            );

            $this->dispatchFaqExtractDebugIfPresent($result['faq_extract_debug'] ?? null);

            if ($result['success']) {
                $remoteIdentity = app(WordPressArticleContentService::class)
                    ->refreshSlugAndPermalinkFromWordPress($article->fresh());
                $syncBody = $result['message'];
                if (! ($remoteIdentity['success'] ?? false)) {
                    $syncBody .= ' Chưa tải lại được slug/permalink mới nhất từ WordPress.';
                }
                if (! empty($result['faq_extract_debug'])) {
                    $headingText = trim((string) ($result['faq_extract_debug']['heading']['text'] ?? ''));
                    $syncBody = ($headingText !== ''
                        ? 'Sync completed but 0 FAQ extracted (detected heading: "'.$headingText.'"). Check FAQ debug block.'
                        : 'Sync completed but 0 FAQ extracted - check FAQ debug block.').' '.$syncBody;
                }

                Notification::make()
                    ->title('WordPress synced')
                    ->body($syncBody)
                    ->success()
                    ->send();

                $this->finishHeavyArticleActionWithReload(clearLocalState: true);

                return;
            }

            $failureBody = (string) ($result['message'] ?? '');

            Notification::make()
                ->title('WordPress sync failed')
                ->body($failureBody !== '' ? $failureBody : 'WordPress sync failed.')
                ->danger()
                ->send();

            $this->cancelHeavyArticleAction();
        } catch (\Throwable $exception) {
            $this->cancelHeavyArticleAction();

            throw $exception;
        }
    }

    private function persistArticleLocalSilent(string $html, bool $syncVirtualCommentsToWordPress = true): string
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
        $postType = SeoProjectTask::normalizePostType($this->articlePostType);

        $this->record->update([
            'title' => trim($this->articleTitle),
            'slug' => $slug !== '' ? $slug : null,
            'type' => $postType,
            'status' => $this->articleStatus,
            'published_at' => $publishAt,
            'body' => $html,
            'user_id' => auth()->id(),
        ]);
        $this->persistArticlePostTypeMeta($postType);
        $this->articlePostType = $postType;
        $this->persistSeoMetaFields();

        $this->articleSlug = $slug;
        $this->syncPublishDatePartsFromRecord();
        app(ArticlePostImagesService::class)->syncFromHtml($this->record, $html);
        $this->record->refresh();

        app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($this->record);

        if ($syncVirtualCommentsToWordPress) {
            $this->syncVirtualCommentsToWordPressIfLinked();
        }

        return $html;
    }

    private function syncVirtualCommentsToWordPressIfLinked(): void
    {
        if ((int) ($this->record->wp_post_id ?? 0) <= 0) {
            return;
        }

        if ($this->getVirtualCommentsCount() === 0) {
            return;
        }

        $result = app(VirtualCommentService::class)->syncToWordPress($this->record->fresh());
        if ($result['success'] ?? false) {
            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.virtual_comments_sync_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->warning()
            ->send();
    }

    public function importMarkdownDebug(string $markdown): void
    {
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $markdown = trim($markdown);
        if ($markdown === '') {
            Notification::make()
                ->title('Markdown trống')
                ->body('Vui lòng nhập nội dung markdown để import.')
                ->warning()
                ->send();

            return;
        }

        $import = app(ArticleContentFaqService::class)->convertMarkdownImport($markdown);
        if ($import['html'] === '') {
            Notification::make()
                ->title('Không thể convert markdown')
                ->body('Nội dung markdown không hợp lệ hoặc rỗng sau khi xử lý.')
                ->warning()
                ->send();

            return;
        }

        $siteId = (int) ($this->record->site_id ?? 0);
        $cta = app(ArticleCtaPlaceholderService::class)->applyForPublish(
            $siteId > 0 ? $siteId : null,
            $import['html'],
            $import['faqs'],
        );
        $html = $cta['html'];

        $faqCount = 0;
        if ($cta['faqs'] !== []) {
            $faqCount = app(ArticleFaqEditorService::class)->saveFromEditor($this->record, $cta['faqs']);
        }

        $h1Title = trim((string) ($import['h1_title'] ?? ''));
        if ($h1Title !== '') {
            $this->articleTitle = $h1Title;
            $this->seoTitle = $h1Title;
        }

        $metaDescription = trim((string) ($import['meta_description'] ?? ''));
        if ($metaDescription !== '') {
            $this->seoMetaDescription = $metaDescription;
        }

        $this->editorHtml = $html;
        $this->persistArticleLocalSilent($html);

        if ($h1Title !== '' || $metaDescription !== '') {
            $this->persistSeoMetaFields();
        }

        app(SeoAnalyzerService::class)->analyze($this->record->fresh());

        $slug = Str::slug($this->articleSlug);
        $seoResult = app(SeoAnalyzerService::class)->analyzePreview(
            $this->record->fresh(),
            $html,
            trim($this->seoTitle) !== '' ? trim($this->seoTitle) : trim($this->articleTitle),
            $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
            trim($this->seoMetaDescription) !== '' ? trim($this->seoMetaDescription) : null,
        );
        $this->dispatch('seo-analyze-result', result: $seoResult);

        $this->dispatch(
            'article-faqs-extracted',
            faqs: app(ArticleFaqEditorService::class)->payloadForArticle($this->record->fresh()),
            editorHtml: $html,
        );

        $importBody = 'Nội dung markdown đã convert sang HTML và cập nhật vào editor.';
        if ($h1Title !== '') {
            $importBody .= ' H1 đã gán làm tiêu đề bài + tiêu đề SEO.';
        }
        if ($faqCount > 0) {
            $importBody .= sprintf(' Đã tách %d FAQ vào panel và chèn shortcode [omi_faq].', $faqCount);
        }

        $addedBlankCta = $cta['added_blank_types'] ?? [];
        if ($addedBlankCta !== []) {
            $importBody .= ' CTA thiếu trên domain (đã thêm biến trắng): '
                .implode(', ', array_map(static fn (string $t): string => "[{$t}]", $addedBlankCta))
                .'.';
        } elseif ($siteId > 0 && app(ArticleCtaPlaceholderService::class)->detectPlaceholderTypes($markdown) !== []) {
            $importBody .= ' Đã thay placeholder CTA bằng giá trị domain (nếu có).';
        }

        $seoPayload = app(ArticleEditorSeoPayloadService::class)->forArticle($this->record->fresh());
        $this->js(sprintf(
            'window.dispatchEvent(new CustomEvent("seo-editor-seo-payload-updated", { detail: %s }))',
            json_encode($seoPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        Notification::make()
            ->title('Đã import markdown debug')
            ->body($importBody)
            ->success()
            ->send();
    }

    public function submitMarkdownImportFromSidebar(string $markdown = ''): void
    {
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $this->importMarkdownDebug($markdown);
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
     * Cấu hình editor (history_step, autosave_interval_seconds trong wp_options). Undo/redo lưu localStorage phía client.
     *
     * @return array{history_step: int, autosave_interval_seconds: int}
     */
    public function getEditorSettingsPayload(): array
    {
        return [
            ...app(ArticleEditorHistoryService::class)->getSettings(),
            'show_reviews_tab' => ! SeoAccessControl::isContentManager(),
            'show_link_widgets' => ! SeoAccessControl::isContentManager(),
            'allow_wp_sync' => ! SeoAccessControl::isContentManager(),
        ];
    }

    /**
     * @return array{id: int, site_id: int, title: string, ai_debug: array<string, mixed>}
     */
    public function getEditorMetaPayload(): array
    {
        $siteId = (int) $this->record->site_id;
        $projectRunRevision = (string) ($this->record->articleMetas()
            ->where('meta_key', 'content_project_run')
            ->value('meta_value') ?? '');
        $contentRevisionSource = $projectRunRevision."\0".$this->editorHtml;
        $productCategoryOptions = $siteId > 0
            ? app(\App\Addons\SeoContentAi\Services\PromptLoaiSanPhamOptionsService::class)
                ->productCategoryOptionsForSite($siteId)
            : [];

        return [
            'id' => (int) $this->record->id,
            'site_id' => $siteId,
            'content_revision' => hash('sha256', $contentRevisionSource),
            'media_picker_url' => route('seo.articles.media-picker', ['article' => $this->record->id]),
            'title' => (string) $this->articleTitle,
            'post_type' => SeoProjectTask::normalizePostType($this->articlePostType),
            'virtual_reviews' => $this->getVirtualReviewsPayload(),
            'supports_product_gallery' => $this->supportsProductGallery(),
            'product_category_options' => collect($productCategoryOptions)
                ->map(static fn (string $label, int $id): array => [
                    'id' => $id,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'product_gallery' => collect($this->productGallery)
                ->map(static fn (array $item): array => [
                    'url' => (string) ($item['url'] ?? ''),
                ])
                ->filter(static fn (array $item): bool => ($item['url'] ?? '') !== '')
                ->values()
                ->all(),
            'preview_url' => $this->getArticlePreviewUrl(),
            'can_sync_wp' => filled($this->record->wp_post_id),
            'loai_san_pham' => $this->supportsProductGallery()
                ? trim((string) ($this->record->articleMetas()
                    ->where('meta_key', 'loai_san_pham')
                    ->value('meta_value') ?? ''))
                : '',
            'gallery_description' => $this->supportsProductGallery()
                ? trim((string) ($this->record->articleMetas()
                    ->where('meta_key', 'gallery_description')
                    ->value('meta_value') ?? ''))
                : '',
            'ai_debug' => $this->getEditorAiDebugPayload(),
            'supplemental_images' => $this->getEditorSupplementalImagesPayload(),
        ];
    }

    /**
     * Cấu hình JS cho modal chọn ảnh (upload thư viện nội bộ).
     *
     * @return array{articleId: int, siteId: int, endpoint: string, wordPressLinked: bool, i18n: array<string, string>}
     */
    public function getArticleMediaPickerPayload(): array
    {
        return [
            'articleId' => (int) $this->record->id,
            'siteId' => (int) $this->record->site_id,
            'endpoint' => route('seo.articles.media-picker', ['article' => $this->record->id]),
            'wordPressLinked' => (int) ($this->record->wp_post_id ?? 0) > 0,
            'i18n' => [
                'upload_success_one' => __('seo-content-ai::filament.media_tools.upload_success_one'),
                'upload_success_many' => __('seo-content-ai::filament.media_tools.upload_success_many'),
                'upload_success_body' => __('seo-content-ai::filament.media_tools.upload_success_body'),
                'upload_failed' => __('seo-content-ai::filament.media_tools.upload_failed'),
                'upload_failed_body' => __('seo-content-ai::filament.media_tools.upload_failed_body'),
            ],
        ];
    }

    /**
     * Ảnh ngoài block editor (ảnh đại diện + album sản phẩm) để hiển thị trong tab Hình ảnh.
     *
     * @return list<array<string, mixed>>
     */
    private function getEditorSupplementalImagesPayload(): array
    {
        $rows = [];
        $seen = [];

        $append = static function (array &$rows, array &$seen, array $row): void {
            $src = trim((string) ($row['src'] ?? ''));
            if ($src === '') {
                return;
            }

            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            $seoId = (int) ($row['seo_media_id'] ?? 0);
            $identity = $wpId > 0
                ? 'wp:'.$wpId
                : ($seoId > 0 ? 'seo:'.$seoId : 'src:'.mb_strtolower($src));
            if (isset($seen[$identity])) {
                return;
            }
            $seen[$identity] = true;
            $rows[] = $row;
        };

        $featuredUrl = trim((string) ($this->featuredImageUrl ?? ''));
        $featuredId = (int) ($this->record->articleMetas->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredUrl !== '') {
            $featuredRefs = $this->resolveSupplementalImageRefIds($featuredUrl, $featuredId);
            $append($rows, $seen, [
                'key' => $featuredId > 0 ? 'featured_wp_'.$featuredId : 'featured_src_'.md5($featuredUrl),
                'block_id' => '',
                'wp_attachment_id' => $featuredRefs['wp_attachment_id'],
                'seo_media_id' => $featuredRefs['seo_media_id'],
                'src' => $featuredUrl,
                'wp_url' => str_contains($featuredUrl, '/storage/uploads/seo_media/') ? '' : $featuredUrl,
                'local_src' => str_contains($featuredUrl, '/storage/uploads/seo_media/') ? $featuredUrl : '',
                'slug' => trim((string) pathinfo(parse_url($featuredUrl, PHP_URL_PATH) ?? $featuredUrl, PATHINFO_FILENAME)),
                'alt' => '',
                'title' => '',
                'caption' => '',
                'align' => 'none',
                'origin' => 'featured',
                'origin_label' => 'Anh dai dien',
            ]);
        }

        foreach ($this->productGallery as $idx => $item) {
            $url = trim((string) ($item['url'] ?? ''));
            $id = (int) ($item['id'] ?? 0);
            if ($url === '') {
                continue;
            }

            $refs = $this->resolveSupplementalImageRefIds($url, $id);
            $append($rows, $seen, [
                'key' => $id > 0 ? 'gallery_wp_'.$id : 'gallery_src_'.md5($url),
                'block_id' => '',
                'wp_attachment_id' => $refs['wp_attachment_id'],
                'seo_media_id' => $refs['seo_media_id'],
                'src' => $url,
                'wp_url' => str_contains($url, '/storage/uploads/seo_media/') ? '' : $url,
                'local_src' => str_contains($url, '/storage/uploads/seo_media/') ? $url : '',
                'slug' => trim((string) pathinfo(parse_url($url, PHP_URL_PATH) ?? $url, PATHINFO_FILENAME)),
                'alt' => '',
                'title' => '',
                'caption' => '',
                'align' => 'none',
                'origin' => 'gallery',
                'origin_label' => $idx === 0 ? 'Anh dai dien' : 'Album san pham',
            ]);
        }

        return $rows;
    }

    /**
     * @return array{wp_attachment_id: int|null, seo_media_id: int|null}
     */
    private function resolveSupplementalImageRefIds(string $url, int $refId): array
    {
        $url = trim($url);
        $refId = max(0, $refId);
        $isLocal = str_contains($url, '/storage/uploads/seo_media/');

        if ($isLocal) {
            $seoId = $refId;
            if ($seoId <= 0) {
                $seoId = app(ArticleMediaLocalService::class)
                    ->resolveLocalRefIdFromImageUrl((int) ($this->record->site_id ?? 0), $url);
            }

            return [
                'wp_attachment_id' => null,
                'seo_media_id' => $seoId > 0 ? $seoId : null,
            ];
        }

        return [
            'wp_attachment_id' => $refId > 0 ? $refId : null,
            'seo_media_id' => null,
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
            $placeholderVars[$name] = '{{'.$name.'}}';
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
                ->title('Unable to extract FAQ')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Unable to extract FAQ')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        $faqs = $result['faqs'] ?? [];
        $editorHtml = (string) ($result['editor_html'] ?? '');

        $this->dispatch('article-faqs-extracted', faqs: $faqs, editorHtml: $editorHtml);

        Notification::make()
            ->title('FAQ extracted and saved')
            ->body('FAQ items: '.count($faqs).'. FAQ content in editor has been replaced with [omi_faq].')
            ->success()
            ->send();
    }

    public function saveArticleFaqs(array $faqs): void
    {
        $previousCount = $this->record->faqs()->count();
        $incomingCount = count(array_filter($faqs, static function ($row): bool {
            if (! is_array($row)) {
                return false;
            }

            return trim((string) ($row['question'] ?? '')) !== '';
        }));

        $savedCount = app(ArticleFaqEditorService::class)->saveFromEditor($this->record, $faqs);

        if ($savedCount > 0) {
            $this->dispatch('article-faqs-save-finished');
        }

        if ($savedCount === 0 && $incomingCount > 0) {
            if ($this->pendingEditorCollectTarget !== null) {
                $target = $this->pendingEditorCollectTarget;
                $this->pendingEditorCollectTarget = null;
                $this->dispatch('collect-editor-html', target: $target);

                return;
            }

            Notification::make()
                ->title('FAQ not saved')
                ->body('Each FAQ needs a question and a non-empty answer.')
                ->warning()
                ->send();

            return;
        }

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
                    ->title('FAQ deleted')
                    ->body((string) ($restore['message'] ?? 'Article content has been restored from WordPress.'))
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
                ->title('FAQ deleted')
                ->body((string) ($restore['message'] ?? 'FAQ has been removed from SEO system.'))
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
                    ->title('FAQ deleted')
                    ->body((string) ($restore['message'] ?? 'Article content has been restored from WordPress.'))
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
            ->title('FAQ saved')
            ->body('FAQ is saved in SEO system. Sync to WordPress when clicking "Sync".')
            ->success()
            ->send();

    }

    /**
     * @return array{rendered: string, prompt_id: int, prompt_name: string, error?: string}
     */
    public function previewGenerateArticleImagePrompt(
        string $userBrief,
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
    ): array {
        return app(ArticleEditorMediaAiService::class)->previewRenderedImagePrompt(
            $this->record,
            $userBrief,
            $target,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
        );
    }

    public function generateArticleImageFromEditor(
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $activeBlockId = '',
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
    ): void {
        try {
            $result = app(ArticleEditorMediaAiService::class)->generateImage(
                $this->record,
                $selectionText,
                $selectionHtml,
                $userBrief,
                $activeBlockId,
                $target,
                $loaiSanPhamCategoryArticleId,
                $loaiSanPhamCustom,
            );
        } catch (\Throwable $exception) {
            $this->dispatch('article-ai-media-failed', type: 'image', message: $exception->getMessage());

            Notification::make()
                ->title(__('seo-content-ai::common.generate_image_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $seoMediaId = (int) ($result['seo_media_id'] ?? 0);
        $galleryUrls = $seoMediaId > 0
            ? $this->resolvePostProcessingGalleryUrlsByMediaId($seoMediaId)
            : [];

        $this->dispatch(
            'article-ai-image-generated',
            url: $result['url'],
            activeBlockId: $activeBlockId,
            seoMediaId: $seoMediaId,
            status: (string) ($result['status'] ?? 'processing'),
            mediaType: 'image',
            target: $target,
            gallery_urls: $galleryUrls,
            galleryUrls: $galleryUrls,
        );

    }

    /**
     * @return list<array{id: int, url: string}>
     */
    private function resolvePostProcessingGalleryUrlsByMediaId(int $seoMediaId): array
    {
        $media = SeoMedia::query()->find($seoMediaId);
        if (! $media instanceof SeoMedia) {
            return [];
        }

        $source = app(PromptPostProcessingApplyService::class)->resolveSourceMedia($media);
        $variables = is_array($source->prompt_variables) ? $source->prompt_variables : [];
        $pieceIds = is_array($variables['post_processing_piece_ids'] ?? null)
            ? $variables['post_processing_piece_ids']
            : [];

        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $pieceIds,
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(static fn (SeoMedia $piece): array => [
                'id' => (int) $piece->id,
                'url' => $piece->publicUrl(),
            ])
            ->values()
            ->all();
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
        } catch (\Throwable $exception) {
            $this->dispatch('article-ai-media-failed', type: 'video', message: $exception->getMessage());

            Notification::make()
                ->title(__('seo-content-ai::common.generate_video_failed'))
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
            ->title(__('seo-content-ai::common.generating_video'))
            ->body(__('seo-content-ai::common.placeholder_inserted'))
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
                ->title('Unable to refresh FAQ')
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

    public function canGenerateArticleFaqs(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null;
    }

    public function requestGenerateArticleFaqs(): void
    {
        if (! $this->canGenerateArticleFaqs()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.faq_generate_failed'))
                ->body(__('seo-content-ai::filament.article_edit.faq_generate_no_prompt'))
                ->warning()
                ->send();

            return;
        }

        $this->pendingEditorCollectTarget = 'generate-faq';
        $this->dispatch('flush-article-faqs');
        $this->dispatch('article-faq-generate-started');
    }

    public function generateArticleFaqs(string $editorHtml = ''): void
    {
        try {
            $result = app(ArticleFaqGeneratorService::class)->generate($this->record, $editorHtml);
        } catch (\InvalidArgumentException $exception) {
            $this->dispatch('article-faq-generate-finished');

            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.faq_generate_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $html = (string) ($result['editor_html'] ?? '');
        if ($html !== '') {
            $this->editorHtml = $html;
        }

        $this->record->refresh();

        $this->dispatch(
            'article-faqs-extracted',
            faqs: $result['faqs'] ?? [],
            editorHtml: $html,
        );
        $this->dispatch('article-faq-generate-finished');

        $count = (int) ($result['faq_count'] ?? 0);

        Notification::make()
            ->title(__('seo-content-ai::filament.article_edit.faq_generate_success'))
            ->body(__('seo-content-ai::filament.article_edit.faq_generate_success_body', ['count' => $count]))
            ->success()
            ->send();
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
     * @return array{success: bool, message: string, outline: string}
     */
    public function rewriteOutlineFromWorkflow(string $mode = 'title', string $title = '', string $html = ''): array
    {
        $mode = $mode === 'content' ? 'content' : 'title';

        $taskId = app(SeoCreateArticleSettingsService::class)->getPublishArticleTaskId();
        if ($taskId === null) {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình workflow Sửa bài viết / Đăng bài viết trong SEO -> Settings -> Workflows.',
                'outline' => '',
            ];
        }

        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy workflow #'.$taskId.'.',
                'outline' => '',
            ];
        }

        if (! $task->is_active) {
            return [
                'success' => false,
                'message' => 'Workflow "'.$task->name.'" đang tắt.',
                'outline' => '',
            ];
        }

        $currentTitle = trim($title !== '' ? $title : (string) ($this->record->title ?? ''));
        if ($currentTitle === '') {
            $currentTitle = 'Article #'.(int) $this->record->id;
        }

        $input = $mode === 'content'
            ? trim(strip_tags($html !== '' ? $html : (string) ($this->record->body ?? '')))
            : $currentTitle;
        if ($input === '') {
            $input = $currentTitle;
        }

        $focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($this->record) ?? $currentTitle;
        $scope = function (\Illuminate\Database\Eloquent\Builder $query): void {
            if (auth()->user()?->role === 'admin') {
                return;
            }

            $query->whereIn(
                'site_id',
                \App\Models\Site::query()->where('user_id', auth()->id())->select('id'),
            );
        };

        try {
            $context = app(TaskTestInputResolver::class)->resolve(
                (int) $this->record->id,
                $currentTitle,
                $focusKeyword,
                $scope,
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'outline' => '',
            ];
        }

        $variables = $context->variables;
        $variables['input'] = $input;
        if ($mode === 'content') {
            $variables['post_content'] = $input;
        }

        $workflowContext = new TaskTestContext(
            article: $context->article,
            isNewArticle: false,
            matchedBy: $context->matchedBy,
            variables: $variables,
            summary: $context->summary,
        );

        try {
            $steps = app(TaskWorkflowTestRunner::class)->run($task, $workflowContext);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'outline' => '',
            ];
        }

        $hasFailedStep = collect($steps)->contains(fn (array $step): bool => (string) ($step['status'] ?? '') === 'failed');
        if ($hasFailedStep) {
            $failedMessage = collect($steps)
                ->filter(fn (array $step): bool => (string) ($step['status'] ?? '') === 'failed')
                ->map(fn (array $step): string => trim((string) ($step['message'] ?? 'Workflow step failed.')))
                ->filter(fn (string $message): bool => $message !== '')
                ->first();

            return [
                'success' => false,
                'message' => $failedMessage !== null ? $failedMessage : 'Workflow chạy lỗi.',
                'outline' => '',
            ];
        }

        app(TaskWorkflowTestRunner::class)->applyParsedMetaFromSteps($this->record, $steps);
        $this->record->refresh();

        $outline = trim($this->getEditorOutlineMarkdown());
        if ($outline === '') {
            $outline = trim((string) collect($steps)
                ->reverse()
                ->map(fn (array $step): string => trim((string) ($step['output'] ?? '')))
                ->first(fn (string $output): bool => $output !== ''));
        }

        if ($outline === '') {
            return [
                'success' => false,
                'message' => 'Workflow đã chạy nhưng chưa tạo được outline.',
                'outline' => '',
            ];
        }

        $this->record->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_article_outline'],
            ['meta_value' => $outline],
        );
        $this->record->refresh();

        return [
            'success' => true,
            'message' => 'Đã tạo outline từ workflow.',
            'outline' => $outline,
        ];
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
                ->title('Image renamed on WordPress')
                ->body($result['message'])
                ->success()
                ->send();

            return;
        }

        $this->dispatch('seo-attachment-slugs-rename-finished', success: false, renamed: $renamed, message: $result['message']);

        Notification::make()
            ->title('Unable to rename image on WordPress')
            ->body($result['message'])
            ->danger()
            ->send();
    }

    /**
     * Cập nhật alt/title attachment trên WordPress (Media Library).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateAttachmentMetaOnWordPress(array $items): void
    {
        $result = app(WordPressAttachmentMetaUpdateService::class)->updateBatch($this->record, $items);

        if ($result['success']) {
            Notification::make()
                ->title('Image alt/title updated on WordPress')
                ->body($result['message'])
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Unable to update image alt/title on WordPress')
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

    private function hydrateSeoMetaState(): void
    {
        $this->record->loadMissing('articleMetas');

        $this->seoTitle = trim((string) ($this->record->articleMetas->firstWhere('meta_key', 'seo_title')?->meta_value ?? ''));

        $this->seoMetaDescription = trim((string) (
            $this->record->articleMetas->first(
                static fn ($meta): bool => in_array((string) $meta->meta_key, [
                    'seo_meta_description',
                    'meta_description',
                ], true),
            )?->meta_value ?? ''
        ));

        // Snapshot lúc mount — dùng để phân biệt «người dùng chủ động xóa»
        // với «state cũ từ tab mở trước khi workflow ghi meta».
        $this->seoTitleHydrated = $this->seoTitle;
        $this->seoMetaDescriptionHydrated = $this->seoMetaDescription;

        $this->focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($this->record) ?? '';
    }

    private function persistSeoMetaFields(): void
    {
        $this->record->loadMissing('articleMetas');

        $seoTitle = trim($this->seoTitle);
        $seoDescription = trim($this->seoMetaDescription);

        if ($seoTitle === '') {
            // Chỉ xóa khi lúc mount field có giá trị (người dùng chủ động xóa).
            // Nếu mount đã rỗng mà DB có giá trị → workflow vừa ghi sau khi tab mở → giữ nguyên.
            if (trim($this->seoTitleHydrated) !== '') {
                $this->record->articleMetas()
                    ->where('meta_key', 'seo_title')
                    ->delete();
            }
        } else {
            $this->record->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_title'],
                ['meta_value' => $seoTitle],
            );
        }

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            if ($seoDescription === '') {
                if (trim($this->seoMetaDescriptionHydrated) !== '') {
                    $this->record->articleMetas()
                        ->where('meta_key', $key)
                        ->delete();
                }

                continue;
            }

            $this->record->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $seoDescription],
            );
        }

        $this->persistFocusKeyword();
    }

    private function persistFocusKeyword(): void
    {
        $siteId = (int) ($this->record->site_id ?? 0);
        if ($siteId <= 0) {
            return;
        }

        KeywordFocusAttach::syncMainKeyword(
            $this->record,
            $siteId,
            (int) auth()->id(),
            trim($this->focusKeyword),
        );
    }

    /**
     * @return array{seo_title: string, meta_description: string, focus_keyword: string}
     */
    private function resolveLivewireSeoPayloadForWordPress(): array
    {
        return [
            'seo_title' => trim($this->seoTitle) !== '' ? trim($this->seoTitle) : trim($this->articleTitle),
            'meta_description' => trim($this->seoMetaDescription),
            'focus_keyword' => trim($this->focusKeyword),
        ];
    }

    private function persistArticlePostTypeMeta(string $postType): void
    {
        $this->record->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_type'],
            ['meta_value' => $postType],
        );
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

        return sprintf('%s %d, %d at %02d:%02d', $weekday, (int) $dt->day, (int) $dt->year, (int) $dt->hour, (int) $dt->minute);
    }
}
