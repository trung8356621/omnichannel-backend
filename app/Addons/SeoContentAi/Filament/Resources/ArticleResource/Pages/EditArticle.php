<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Services\ArticleEditorHistoryService;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\WordPressArticleContentService;
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

    public function savePublish(): void
    {
        $slug = Str::slug($this->articleSlug);

        $this->record->update([
            'title' => trim($this->articleTitle),
            'slug' => $slug !== '' ? $slug : null,
            'status' => $this->articleStatus,
            'user_id' => auth()->id(),
        ]);

        $this->articleSlug = $slug;
        $this->editingSlug = false;

        Notification::make()
            ->title('Đã cập nhật thông tin xuất bản')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function getEditorSeoPayload(): array
    {
        $this->record->loadMissing(['articleMetas', 'keywords', 'site']);

        $analysis = $this->decodeArticleMetaJson('seo_rank_math_score');
        $extractedLinks = $this->decodeArticleMetaJson('seo_extracted_links');

        if (! is_array($analysis) && $this->record->seo_score !== null) {
            $analysis = [
                'score' => (int) round((float) $this->record->seo_score),
                'good' => [],
                'errors' => [],
                'warnings' => [],
            ];
        }

        return [
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($this->record),
            'article_type' => (string) ($this->record->type ?? 'post'),
            'score' => $this->record->seo_score !== null ? (int) round((float) $this->record->seo_score) : null,
            'analysis' => is_array($analysis) ? $analysis : null,
            'extracted_links' => is_array($extractedLinks) ? $extractedLinks : [
                'internal' => [],
                'external' => [],
            ],
        ];
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

    public function saveContent(string $html, bool $silent = false): void
    {
        $this->record->update([
            'body' => $html,
            'user_id' => auth()->id(),
        ]);

        // Không gán lại $editorHtml — tránh Livewire re-render làm React parse lại HTML và mất block gốc.
        $this->record->refresh();

        app(SeoAnalyzerService::class)->analyze($this->record->fresh());

        $slug = Str::slug($this->articleSlug);
        $seoResult = app(SeoAnalyzerService::class)->analyzePreview(
            $this->record->fresh(),
            $html,
            trim($this->articleTitle),
            $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
        );
        $this->dispatch('seo-analyze-result', result: $seoResult);

        if ($silent) {
            $this->js('window.dispatchEvent(new CustomEvent("seo-article-saved"))');
        }

        if (! $silent) {
            Notification::make()
                ->title('Đã lưu nội dung bài viết')
                ->success()
                ->send();
        }
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
