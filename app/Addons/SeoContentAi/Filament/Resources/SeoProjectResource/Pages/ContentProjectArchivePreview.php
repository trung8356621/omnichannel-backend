<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProjectArchive;
use App\Addons\SeoContentAi\Models\SeoProjectArchiveItem;
use App\Addons\SeoContentAi\Support\ContentProject\ArchivePreviewArticlePresenter;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Throwable;

final class ContentProjectArchivePreview extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.content-project-archive-preview';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Route parameter `{archive}` — scalar only; model loaded in mount().
     * Do not type this as SeoProjectArchive (Livewire would 404 on binding).
     */
    public int|string $archive = 0;

    public ?SeoProjectArchive $archiveRecord = null;

    public ?string $snapshotLoadError = null;

    /** @var list<array<string, mixed>> */
    public array $articleRows = [];

    public function mount(int|string $archive): void
    {
        self::authorizeResourceAccess();

        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        $this->archive = (int) $archive;
        $this->snapshotLoadError = null;
        $this->articleRows = [];

        try {
            $this->archiveRecord = SeoProjectArchive::query()
                ->current()
                ->with([
                    'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
                    'archivedByUser',
                    'owner',
                    'site',
                    'project',
                ])
                ->findOrFail((int) $this->archive);

            $this->rebuildArticleRows();
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'archive_id' => (int) $this->archive,
                'source_project_id' => null,
                'endpoint' => 'content-project-archive-preview',
            ]);

            $this->archiveRecord = SeoProjectArchive::query()
                ->current()
                ->find((int) $this->archive);

            $this->snapshotLoadError = __('seo-content-ai::filament.projects.archive_preview_snapshot_error');

            if (! $this->archiveRecord instanceof SeoProjectArchive) {
                throw $exception;
            }

            try {
                $this->archiveRecord->load([
                    'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
                ]);
                $this->rebuildArticleRows();
            } catch (Throwable) {
                $this->articleRows = [];
            }
        }

        $siteId = (int) ($this->archiveRecord->site_id ?? 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);
    }

    public function getTitle(): string|Htmlable
    {
        $name = trim((string) ($this->archiveRecord?->project_name ?? ''));

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

    public function viewArchiveItemAction(): Action
    {
        return Action::make('viewArchiveItem')
            ->label(__('seo-content-ai::filament.projects.archive_preview_item'))
            ->slideOver()
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->stickyModalHeader()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('seo-content-ai::filament.projects.archive_preview_close'))
            ->extraModalWindowAttributes([
                'class' => 'fi-archive-preview-item-slideover',
            ])
            ->modalHeading(function (array $arguments): HtmlString {
                $row = $this->findRow((int) ($arguments['itemId'] ?? 0));
                $title = trim((string) ($row['title'] ?? ''));
                $badge = e(__('seo-content-ai::filament.projects.archive_preview_badge_archived'));

                return new HtmlString(
                    '<div class="flex flex-col gap-2 pe-6">'
                    .'<span class="inline-flex w-fit items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">'
                    .$badge
                    .'</span>'
                    .'<span class="text-base font-semibold text-gray-950 dark:text-white">'
                    .e($title !== '' ? $title : __('seo-content-ai::filament.projects.archive_preview_no_data'))
                    .'</span>'
                    .'</div>'
                );
            })
            ->extraModalFooterActions(function (Action $action): array {
                $arguments = $action->getArguments();
                $row = $this->findRow((int) ($arguments['itemId'] ?? 0));
                $editUrl = is_string($row['edit_url'] ?? null) ? $row['edit_url'] : null;

                if ($editUrl === null || $editUrl === '' || ! ($row['can_edit'] ?? false)) {
                    return [];
                }

                return [
                    Action::make('editArticle')
                        ->label(__('seo-content-ai::filament.projects.archive_preview_edit_article'))
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->url($editUrl, shouldOpenInNewTab: true),
                ];
            })
            ->modalContent(function (array $arguments) {
                $row = $this->findRow((int) ($arguments['itemId'] ?? 0));

                return view(
                    'seo-content-ai::filament.resources.seo-project-resource.partials.archive-preview-item-slideover',
                    ['row' => $row],
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaderSummary(): array
    {
        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            return [];
        }

        $snapshot = is_array($this->archiveRecord->summary_snapshot) ? $this->archiveRecord->summary_snapshot : [];

        return [
            'project_name' => (string) ($this->archiveRecord->project_name ?: ($snapshot['project_name'] ?? '')),
            'domain' => trim((string) ($this->archiveRecord->site?->domain ?? ($snapshot['domain_name'] ?? ''))),
            'owner' => trim((string) ($this->archiveRecord->owner?->display_name ?? $this->archiveRecord->owner?->name ?? ($snapshot['owner_name'] ?? ''))),
            'month' => (int) ($this->archiveRecord->project_month ?? ($snapshot['month'] ?? 0)),
            'year' => (int) ($this->archiveRecord->project_year ?? ($snapshot['year'] ?? 0)),
            'total_articles' => (int) ($this->archiveRecord->total_articles ?? $this->archiveRecord->articles_count ?? ($snapshot['total_articles'] ?? 0)),
            'completed_articles' => (int) ($this->archiveRecord->completed_articles ?? ($snapshot['completed_articles'] ?? 0)),
            'synced_articles' => (int) ($this->archiveRecord->synced_articles ?? ($snapshot['synced_articles'] ?? 0)),
            'average_seo_score' => $this->archiveRecord->average_seo_score ?? ($snapshot['average_seo_score'] ?? null),
            'archived_at' => $this->archiveRecord->archived_at,
            'archived_by' => trim((string) ($this->archiveRecord->archivedByUser?->display_name ?? $this->archiveRecord->archivedByUser?->name ?? '')),
            'note' => trim((string) ($this->archiveRecord->note ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findRow(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        foreach ($this->articleRows as $row) {
            if ((int) ($row['item_id'] ?? 0) === $itemId) {
                return $row;
            }
        }

        return [];
    }

    private function rebuildArticleRows(): void
    {
        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            $this->articleRows = [];

            return;
        }

        /** @var Collection<int, SeoProjectArchiveItem> $items */
        $items = $this->archiveRecord->items instanceof Collection
            ? $this->archiveRecord->items
            : collect($this->archiveRecord->items ?? []);

        $presenter = app(ArchivePreviewArticlePresenter::class);
        $articlesById = $presenter->loadArticlesById($items);
        $this->articleRows = $presenter->presentItems($items, $articlesById);
    }
}
