<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Services\SeoProjectArchiveService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class ContentProjectArchive extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.content-project-archive';

    protected static bool $shouldRegisterNavigation = false;

    public int $siteId = 0;

    public ?int $unarchiveSubmittingId = null;

    public function mount(): void
    {
        self::authorizeResourceAccess();

        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        $siteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
        abort_if($siteId <= 0, 403);

        $this->siteId = $siteId;
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.projects.archive_dashboard_heading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_projects')
                ->label(__('seo-content-ai::filament.projects.back_to_projects'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('index')),
        ];
    }

    public function unarchiveItem(int $archiveItemId): void
    {
        abort_unless(SeoAccessControl::canArchiveContentProjects(), 403);

        if ($archiveItemId <= 0 || $this->siteId <= 0) {
            $this->skipRender();

            return;
        }

        $this->unarchiveSubmittingId = $archiveItemId;

        try {
            app(SeoProjectArchiveService::class)->unarchiveItem(
                $archiveItemId,
                $this->siteId,
                (int) auth()->id(),
            );

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.unarchive_item_completed'))
                ->body(__('seo-content-ai::filament.projects.unarchive_item_completed_body'))
                ->success()
                ->send();

            $this->redirect(static::getUrl());
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.unarchive_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->unarchiveSubmittingId = null;
        }
    }

    public function canUnarchiveArchiveItems(): bool
    {
        return SeoAccessControl::canArchiveContentProjects();
    }
}
