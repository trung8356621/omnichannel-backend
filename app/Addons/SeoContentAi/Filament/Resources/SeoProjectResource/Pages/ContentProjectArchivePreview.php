<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectArchive;
use App\Addons\SeoContentAi\Models\SeoProjectArchiveItem;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

final class ContentProjectArchivePreview extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.content-project-archive-preview';

    protected static bool $shouldRegisterNavigation = false;

    public int $archiveId = 0;

    public ?SeoProjectArchive $archive = null;

    public ?int $selectedItemId = null;

    public function mount(int|string $archive): void
    {
        self::authorizeResourceAccess();

        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        $this->archiveId = (int) $archive;
        $this->archive = SeoProjectArchive::query()
            ->current()
            ->with([
                'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
                'items.article.articleMetas',
                'archivedByUser',
                'owner',
                'site',
                'project',
            ])
            ->findOrFail($this->archiveId);

        $siteId = (int) ($this->archive->site_id ?? 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);
    }

    public function getTitle(): string|Htmlable
    {
        $name = trim((string) ($this->archive?->project_name ?? ''));

        return $name !== ''
            ? __('seo-content-ai::filament.projects.archive_preview_heading').': '.$name
            : __('seo-content-ai::filament.projects.archive_preview_heading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_archive')
                ->label(__('seo-content-ai::filament.projects.open_site_archive'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('archive')),
        ];
    }

    public function openItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        $exists = $this->archive?->items?->contains(
            static fn (SeoProjectArchiveItem $item): bool => (int) $item->getKey() === $itemId,
        );

        $this->selectedItemId = $exists ? $itemId : null;
    }

    public function closeItem(): void
    {
        $this->selectedItemId = null;
    }

    public function getSelectedItemProperty(): ?SeoProjectArchiveItem
    {
        if ($this->selectedItemId === null || $this->archive === null) {
            return null;
        }

        $item = $this->archive->items->firstWhere('id', $this->selectedItemId);

        return $item instanceof SeoProjectArchiveItem ? $item : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaderSummary(): array
    {
        if (! $this->archive instanceof SeoProjectArchive) {
            return [];
        }

        $snapshot = is_array($this->archive->summary_snapshot) ? $this->archive->summary_snapshot : [];

        return [
            'project_name' => (string) ($this->archive->project_name ?: ($snapshot['project_name'] ?? '')),
            'domain' => trim((string) ($this->archive->site?->domain ?? ($snapshot['domain_name'] ?? ''))),
            'owner' => trim((string) ($this->archive->owner?->display_name ?? $this->archive->owner?->name ?? ($snapshot['owner_name'] ?? ''))),
            'month' => (int) ($this->archive->project_month ?? ($snapshot['month'] ?? 0)),
            'year' => (int) ($this->archive->project_year ?? ($snapshot['year'] ?? 0)),
            'total_articles' => (int) ($this->archive->total_articles ?? $this->archive->articles_count ?? ($snapshot['total_articles'] ?? 0)),
            'completed_articles' => (int) ($this->archive->completed_articles ?? ($snapshot['completed_articles'] ?? 0)),
            'approved_articles' => (int) ($this->archive->approved_articles ?? ($snapshot['approved_articles'] ?? 0)),
            'synced_articles' => (int) ($this->archive->synced_articles ?? ($snapshot['synced_articles'] ?? 0)),
            'average_seo_score' => $this->archive->average_seo_score ?? ($snapshot['average_seo_score'] ?? null),
            'archived_at' => $this->archive->archived_at,
            'archived_by' => trim((string) ($this->archive->archivedByUser?->display_name ?? $this->archive->archivedByUser?->name ?? '')),
            'note' => trim((string) ($this->archive->note ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildItemDetails(SeoProjectArchiveItem $item): array
    {
        $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
        $article = $item->article;

        $title = $this->firstNonEmpty([
            $snapshot['title'] ?? null,
            $article?->title,
        ]);
        $slug = $this->firstNonEmpty([
            $snapshot['slug'] ?? null,
            $article?->slug,
        ]);
        $keyword = $this->firstNonEmpty([
            $snapshot['primary_keyword'] ?? null,
            $this->articleMeta($article, 'seo_focus_keyword'),
        ]);
        $metaTitle = $this->firstNonEmpty([
            $snapshot['meta_title'] ?? null,
            $this->articleMeta($article, 'seo_title'),
        ]);
        $metaDescription = $this->firstNonEmpty([
            $snapshot['meta_description'] ?? null,
            $this->articleMeta($article, 'seo_meta_description'),
        ]);
        $outlineMeta = $this->firstNonEmpty([
            $this->articleMeta($article, 'outline_meta'),
            $this->articleMeta($article, 'seo_outline'),
        ]);

        $bodyExcerpt = $this->buildBodyExcerpt($article);
        $imageCount = (int) ($snapshot['image_count'] ?? 0);
        $seoScore = $snapshot['seo_score'] ?? $article?->seo_score;
        $syncStatus = $this->firstNonEmpty([
            $snapshot['sync_status'] ?? null,
            $article?->wp_sync_status,
        ]);
        $wpPostId = $this->firstNonEmpty([
            $snapshot['wordpress_post_id'] ?? null,
            $article?->wp_post_id,
        ]);
        $wpUrl = $this->firstNonEmpty([
            $snapshot['wordpress_url'] ?? null,
            $this->articleMeta($article, 'wp_permalink'),
        ]);
        $wpSyncError = $this->firstNonEmpty([
            $snapshot['wp_sync_error'] ?? null,
        ]);

        return [
            'item_id' => (int) $item->getKey(),
            'article_id' => (int) ($item->article_id ?? ($snapshot['article_id'] ?? 0)),
            'title' => is_string($title) ? $title : '',
            'slug' => is_string($slug) ? $slug : '',
            'keyword' => is_string($keyword) ? $keyword : '',
            'meta_title' => is_string($metaTitle) ? $metaTitle : '',
            'meta_description' => is_string($metaDescription) ? $metaDescription : '',
            'outline_meta' => is_string($outlineMeta) ? $outlineMeta : '',
            'body_excerpt' => $bodyExcerpt,
            'image_count' => $imageCount,
            'seo_score' => $seoScore !== null ? (float) $seoScore : null,
            'task_status' => (string) ($snapshot['status'] ?? $item->task?->status ?? ''),
            'approved_status' => (string) ($snapshot['approved_status'] ?? $article?->review_status ?? ''),
            'sync_status' => is_string($syncStatus) ? $syncStatus : '',
            'wordpress_post_id' => $wpPostId !== null ? (int) $wpPostId : null,
            'wordpress_url' => is_string($wpUrl) ? $wpUrl : '',
            'wp_sync_error' => is_string($wpSyncError) ? $wpSyncError : '',
            'created_at' => SeoProjectResource::formatTaskTimestamp($snapshot['created_at'] ?? $article?->created_at),
            'updated_at' => SeoProjectResource::formatTaskTimestamp($snapshot['updated_at'] ?? $article?->updated_at),
            'completed_at' => SeoProjectResource::formatTaskTimestamp($snapshot['completed_at'] ?? $item->task?->completed_at),
            'last_saved_at' => SeoProjectResource::formatTaskTimestamp($snapshot['last_saved_at'] ?? null),
            'last_synced_at' => SeoProjectResource::formatTaskTimestamp($snapshot['last_synced_at'] ?? $article?->last_synced_at),
            'article_exists' => $article instanceof SeoArticle,
        ];
    }

    private function buildBodyExcerpt(?SeoArticle $article): string
    {
        if (! $article instanceof SeoArticle) {
            return '';
        }

        $text = trim(strip_tags((string) ($article->body ?? '')));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        if ($text === '') {
            return '';
        }

        return Str::limit($text, 500);
    }

    private function articleMeta(?SeoArticle $article, string $key): ?string
    {
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $article->loadMissing('articleMetas');
        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }
}
