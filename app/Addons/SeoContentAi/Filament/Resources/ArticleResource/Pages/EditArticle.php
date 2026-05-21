<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Services\ArticleEditorHistoryService;
use App\Addons\SeoContentAi\Services\ArticleEditorSeoPayloadService;
use App\Addons\SeoContentAi\Services\ArticleFaqBodySyncService;
use App\Addons\SeoContentAi\Services\ArticleFaqEditorService;
use App\Addons\SeoContentAi\Exceptions\FaqManualExtractException;
use App\Addons\SeoContentAi\Services\ArticleFaqExtractDebugService;
use App\Addons\SeoContentAi\Services\ArticleFaqManualExtractService;
use App\Addons\SeoContentAi\Services\ArticleFaqWordPressImportService;
use App\Addons\SeoContentAi\Services\ArticlePostImagesService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
use App\Addons\SeoContentAi\Services\WordPressAttachmentRenameService;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Str;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.edit-article';

    public string $articleTitle = '';

    public string $articleSlug = '';

    public string $articleStatus = 'draft';

    public ?string $featuredImageUrl = null;

    /** @var array<int, array{id: int, url: string}> */
    public array $productGallery = [];

    public bool $editingSlug = false;

    public string $editorHtml = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->hydrateArticleState();
        $this->importFaqsFromWordPressOnLoad();
    }

    private function importFaqsFromWordPressOnLoad(): void
    {
        if ((int) ($this->record->wp_post_id ?? 0) > 0) {
            $this->record->loadCount('faqs');
            if ($this->record->faqs_count === 0) {
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

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function hydrateArticleState(): void
    {
        $service = app(WordPressArticleContentService::class);

        $this->articleTitle = (string) ($this->record->title ?? '');
        $this->articleSlug = $service->resolveSlug($this->record);
        $this->articleStatus = (string) ($this->record->status ?? 'draft');
        $this->featuredImageUrl = $service->resolveFeaturedImageUrl($this->record);
        $this->productGallery = $this->isProduct()
            ? $service->resolveProductGallery($this->record)
            : [];
        $this->editorHtml = $service->resolveEditorHtml($this->record);
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
        $publishedAt = $this->record->published_at;
        if ($publishedAt === null) {
            return null;
        }

        return $publishedAt->timezone(config('app.timezone'))->format('d/m/Y H:i');
    }

    public function requestSaveArticle(): void
    {
        $this->dispatch('flush-article-faqs');
        $this->dispatch('collect-editor-html', target: 'save');
    }

    public function requestSyncToWordPress(): void
    {
        $this->dispatch('flush-article-faqs');
        $this->dispatch('collect-editor-html', target: 'sync');
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
        $faqSync = app(ArticleFaqBodySyncService::class)->extractFromBodyWhenMissing($this->record, $html);
        $html = $faqSync['body_html'];
        if ($faqSync['extracted']) {
            $this->dispatch('article-faqs-extracted', faqs: $faqSync['faqs'], editorHtml: $html);
        } else {
            $this->dispatchFaqExtractDebugIfPresent($faqSync['extract_debug'] ?? null);
        }

        $slug = Str::slug($this->articleSlug);

        $this->record->update([
            'title' => trim($this->articleTitle),
            'slug' => $slug !== '' ? $slug : null,
            'status' => $this->articleStatus,
            'body' => $html,
            'user_id' => auth()->id(),
        ]);

        $this->articleSlug = $slug;
        $this->editingSlug = false;

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
        $faqSync = app(ArticleFaqBodySyncService::class)->extractFromBodyWhenMissing($this->record, $html);
        $html = $faqSync['body_html'];
        if ($faqSync['extracted']) {
            $this->dispatch('article-faqs-extracted', faqs: $faqSync['faqs'], editorHtml: $html);
        } else {
            $this->dispatchFaqExtractDebugIfPresent($faqSync['extract_debug'] ?? null);
        }

        $slug = Str::slug($this->articleSlug);

        $this->record->update([
            'title' => trim($this->articleTitle),
            'slug' => $slug !== '' ? $slug : null,
            'status' => $this->articleStatus,
            'body' => $html,
            'user_id' => auth()->id(),
        ]);

        $this->articleSlug = $slug;
        app(ArticlePostImagesService::class)->syncFromHtml($this->record, $html);
        $this->record->refresh();
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
        $count = app(ArticleFaqEditorService::class)->saveFromEditor($this->record, $faqs);

        Notification::make()
            ->title($count > 0 ? 'Đã lưu FAQ' : 'Đã xóa FAQ')
            ->body('FAQ lưu trên hệ thống SEO. Đồng bộ WordPress khi bấm «Đồng bộ».')
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
}
