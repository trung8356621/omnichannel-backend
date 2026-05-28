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

    /** @var 'original'|'local'|'article' */
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

    public function updatedArticleSlug($value): void
    {
        $normalized = Str::slug((string) $value);
        if ($this->articleSlug !== $normalized) {
            $this->articleSlug = $normalized;
        }
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
                ->label('Restore from WordPress')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->iconButton()
                ->tooltip('Fetch original content from WordPress')
                ->visible(fn (): bool => (int) ($this->record->wp_post_id ?? 0) > 0)
                ->requiresConfirmation()
                ->modalHeading('Restore original WordPress article')
                ->modalDescription('Replace editor content and clear FAQ panel with original WordPress content. Unsaved or unsynced SEO edits will be overwritten.')
                ->modalSubmitActionLabel('Restore')
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
                ->title('Restore failed')
                ->body((string) ($restore['message'] ?? 'Could not fetch content from WordPress.'))
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
            ? app(ArticleMediaLocalService::class)->resolveProductAlbum($this->record)
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
            ->title('Restored from WordPress')
            ->body((string) ($restore['message'] ?? 'Editor content has been replaced with original WordPress version.'))
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
            ? app(ArticleMediaLocalService::class)->resolveProductAlbum($this->record)
            : [];
        if ($this->supportsProductGallery()) {
            $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        }
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
                Notification::make()
                    ->title('Unable to identify image block')
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

        $this->mediaPickerTab = $this->mediaPickerMode === 'editor-block' ? 'article' : 'original';
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
        $tab = match ($tab) {
            'local', 'article' => $tab,
            default => 'original',
        };
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
                200,
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
                    ? 'wp:' . $wpId
                    : ($seoId > 0 ? 'seo:' . $seoId : 'src:' . mb_strtolower($src));
                if (isset($seen[$identity])) {
                    return;
                }

                $seen[$identity] = true;
                $merged[] = [
                    'id' => (int) ($row['id'] ?? ($wpId > 0 ? $wpId : ($seoId > 0 ? $seoId : count($merged) + 1))),
                    'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                    'seo_media_id' => $seoId > 0 ? $seoId : null,
                    'url' => $src,
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

            if ($search !== '') {
                $needle = mb_strtolower($search);
                $merged = array_values(array_filter($merged, static function (array $row) use ($needle): bool {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        (string) ($row['slug'] ?? ''),
                        (string) ($row['alt'] ?? ''),
                        (string) ($row['url'] ?? ''),
                    ])));

                    return str_contains($haystack, $needle);
                }));
            }

            $perPage = 48;
            $total = count($merged);
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, $this->mediaPickerPage), $totalPages);
            $offset = ($page - 1) * $perPage;

            $result = [
                'images' => array_slice($merged, $offset, $perPage),
                'total_pages' => $totalPages,
                'page' => $page,
                'error' => null,
            ];
            $this->mediaPickerImages = $result['images'];
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
        }
        $this->mediaPickerTotalPages = max(1, (int) ($result['total_pages'] ?? 1));
        $this->mediaPickerPage = max(1, (int) ($result['page'] ?? $this->mediaPickerPage));
        $this->mediaPickerError = filled($result['error'] ?? null) ? (string) $result['error'] : null;
        $this->mediaPickerLoading = false;
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

            if ($this->mediaPickerTab === 'original' && $wpAttachmentId <= 0) {
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
                ->title('Image selected for block')
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
                    ->title('Album not applicable')
                    ->body('Category only supports featured image.')
                    ->warning()
                    ->send();

                return;
            }

            $this->productGallery = $localMedia->appendProductAlbumLocal($this->record, $localRefId, $url);
            $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
            $title = 'Added to album (saved locally)';
        } else {
            $localMedia->applyFeaturedLocal($this->record, $localRefId, $url);
            $this->featuredImageUrl = trim($url);
            $title = 'Featured image selected (saved locally)';
        }

        $this->record->refresh();
        $this->dispatch(
            'article-media-selected',
            mode: (string) $this->mediaPickerMode,
            url: $url,
            wpAttachmentId: $wpAttachmentId > 0 ? $wpAttachmentId : null,
            seoMediaId: $seoMediaId > 0 ? $seoMediaId : null,
            slug: trim($slug),
            alt: trim($alt),
        );

        if ($this->mediaPickerMode !== 'gallery') {
            $this->dispatch('close-article-media-modal');
        } else {
            $this->loadMediaPickerImages();
        }

        Notification::make()
            ->title($title)
            ->body(
                $this->mediaPickerMode === 'gallery'
                    ? 'Continue selecting images or close popup when done.'
                    : 'Click "Sync" to upload images to WordPress.'
            )
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

    public function getPermalinkSuffix(): string
    {
        $permalink = trim($this->getArticlePermalink());
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

        $prefix = $slug . '.';
        if (str_starts_with($basename, $prefix)) {
            return substr($basename, strlen($slug));
        }

        return '';
    }

    public function getDisplayPermalink(): string
    {
        $permalink = trim($this->getArticlePermalink());
        if ($permalink !== '') {
            return $permalink;
        }

        $base = $this->getPermalinkBase();
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/' . $this->getDisplaySlug() . $this->getPermalinkSuffix();
    }

    public function getArticlePermalink(): string
    {
        return app(WordPressArticleContentService::class)->resolvePermalink($this->record);
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
                ->title('Invalid date/time')
                ->body('Please review scheduled publish date/time.')
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

        $saveBody = 'Content is saved only in SEO system. Use "Sync" to push to WordPress.';
        if ($faqSync['extracted']) {
            $saveBody = 'Extracted ' . $faqSync['faq_count'] . ' FAQ items from content into FAQ panel. ' . $saveBody;
        } elseif (! empty($faqSync['extract_debug'])) {
            $saveBody = 'FAQ heading exists but questions/answers were not extracted - check FAQ debug block. ' . $saveBody;
        }

        Notification::make()
            ->title('Article saved')
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
                    ? 'Sync completed but 0 FAQ extracted (detected heading: "' . $headingText . '"). Check FAQ debug block.'
                    : 'Sync completed but 0 FAQ extracted - check FAQ debug block.') . ' ' . $syncBody;
            }

            Notification::make()
                ->title('WordPress synced')
                ->body($syncBody)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('WordPress sync failed')
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
            'post_type' => (string) ($this->record->post_type ?? ''),
            'ai_debug' => $this->getEditorAiDebugPayload(),
            'supplemental_images' => $this->getEditorSupplementalImagesPayload(),
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
                ? 'wp:' . $wpId
                : ($seoId > 0 ? 'seo:' . $seoId : 'src:' . mb_strtolower($src));
            if (isset($seen[$identity])) {
                return;
            }
            $seen[$identity] = true;
            $rows[] = $row;
        };

        $featuredUrl = trim((string) ($this->featuredImageUrl ?? ''));
        $featuredId = (int) ($this->record->articleMetas->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredUrl !== '') {
            $append($rows, $seen, [
                'key' => $featuredId > 0 ? 'featured_wp_' . $featuredId : 'featured_src_' . md5($featuredUrl),
                'block_id' => '',
                'wp_attachment_id' => $featuredId > 0 ? $featuredId : null,
                'seo_media_id' => null,
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

            $append($rows, $seen, [
                'key' => $id > 0 ? 'gallery_wp_' . $id : 'gallery_src_' . md5($url),
                'block_id' => '',
                'wp_attachment_id' => $id > 0 ? $id : null,
                'seo_media_id' => null,
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
            ->body('FAQ items: ' . count($faqs) . '. FAQ content in editor has been replaced with [omi_faq].')
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
        } catch (\Throwable $exception) {
            $this->dispatch('article-ai-media-failed', type: 'image', message: $exception->getMessage());

            Notification::make()
                ->title(__('seo-content-ai::common.generate_image_failed'))
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
            ->title(__('seo-content-ai::common.generating_image'))
            ->body(__('seo-content-ai::common.placeholder_inserted'))
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

        return sprintf('%s %d, %d at %02d:%02d', $weekday, (int) $dt->day, (int) $dt->year, (int) $dt->hour, (int) $dt->minute);
    }
}
