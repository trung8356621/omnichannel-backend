<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Services\SeoQueueControlService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Notifications\Notification;

class SeoQueueManager extends SeoPanelPage
{
    protected static ?string $slug = 'queue-manager';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'seo-content-ai::filament.pages.seo-queue-manager';

    /** @var array<string, mixed> */
    public array $queueStatus = [];

    public function mount(SeoQueueControlService $queueControl): void
    {
        $this->refreshQueueStatus($queueControl);
    }

    public function refreshQueueStatus(?SeoQueueControlService $queueControl = null): void
    {
        $queueControl ??= app(SeoQueueControlService::class);
        $this->queueStatus = $queueControl->statusForCurrentOwner();
    }

    public function pauseQueue(SeoQueueControlService $queueControl): void
    {
        SeoAccessControl::guardSeoPanelMutation();

        $queueControl->pauseForCurrentOwner();
        $this->refreshQueueStatus($queueControl);

        Notification::make()
            ->title(__('seo-content-ai::filament.queue_manager.paused_title'))
            ->body(__('seo-content-ai::filament.queue_manager.paused_body'))
            ->warning()
            ->send();
    }

    public function resumeQueue(SeoQueueControlService $queueControl): void
    {
        SeoAccessControl::guardSeoPanelMutation();

        $queueControl->resumeForCurrentOwner();
        $this->refreshQueueStatus($queueControl);

        Notification::make()
            ->title(__('seo-content-ai::filament.queue_manager.resumed_title'))
            ->success()
            ->send();
    }

    public function stopAuditJobs(SeoQueueControlService $queueControl): void
    {
        SeoAccessControl::guardSeoPanelMutation();

        $removed = $queueControl->stopAuditJobsForCurrentOwner();
        $this->refreshQueueStatus($queueControl);

        Notification::make()
            ->title(__('seo-content-ai::filament.queue_manager.stopped_title'))
            ->body(__('seo-content-ai::filament.queue_manager.stopped_body', ['count' => $removed]))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.queue_manager');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.queue_manager.title');
    }

    protected function isPanelReadOnly(): bool
    {
        return SeoAccessControl::isSeoPanelReadOnly();
    }
}
