<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Jobs\RunIncrementalDomainSyncJob;
use App\Addons\SeoContentAi\Jobs\RunKeywordDomainResyncJob;
use App\Addons\SeoContentAi\Services\ClearDomainArticlesService;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use App\Addons\SeoContentAi\Services\IncrementalDomainSyncRunner;
use App\Addons\SeoContentAi\Services\LinkMapStatusAuditService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Services\SyncDomainContentService;
use App\Addons\SeoContentAi\Support\IncrementalDomainSyncCache;
use App\Addons\SeoContentAi\Support\KeywordDomainResyncCache;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GeneralDomain extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.general-domain';

    public string $internalLinkTab = 'keywords';

    public bool $tokensUnlocked = false;

    public bool $readTokenVisible = false;

    public bool $migrationTokenVisible = false;

    public bool $showPasswordPrompt = false;

    public ?string $pendingRevealField = null;

    public string $tokenPassword = '';

    public int $incrementalSyncProgress = 0;

    public int $incrementalSyncTotal = 0;

    public bool $incrementalSyncRunning = false;

    public bool $incrementalSyncResumable = false;

    public bool $keywordResyncRunning = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);

        $this->tokensUnlocked = $this->canRevealTokensWithoutPassword();

        $tab = request()->query('tab');
        if (is_string($tab) && in_array($tab, ['keywords', 'links'], true)) {
            $this->internalLinkTab = $tab;
        }

        $this->restoreIncrementalSyncProgressFromCache();
        $this->refreshKeywordResyncProgress();
    }

    public function refreshKeywordResyncProgress(): void
    {
        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();

        KeywordDomainResyncCache::clearIfStale($userId, $siteId);

        $progress = KeywordDomainResyncCache::progressFromState(
            KeywordDomainResyncCache::read($userId, $siteId),
        );

        $wasRunning = $this->keywordResyncRunning;
        $this->keywordResyncRunning = (bool) $progress['running'];

        if ($wasRunning && ! $this->keywordResyncRunning) {
            if (($progress['status'] ?? '') === KeywordDomainResyncCache::STATUS_COMPLETED) {
                $this->dispatch('domain-sync-completed');
            } elseif (($progress['status'] ?? '') === KeywordDomainResyncCache::STATUS_FAILED) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.resync_linked_failed'))
                    ->body((string) ($progress['message'] ?? ''))
                    ->danger()
                    ->send();
            }
        }
    }

    public function refreshIncrementalSyncProgress(): void
    {
        $wasRunning = $this->incrementalSyncRunning;

        $this->applyIncrementalSyncProgress(
            app(IncrementalDomainSyncRunner::class)->readProgress(
                (int) auth()->id(),
                (int) $this->getRecord()->getKey(),
            ),
        );

        if ($wasRunning && ! $this->incrementalSyncRunning && $this->incrementalSyncTotal > 0) {
            $this->dispatch('domain-sync-completed');
        }
    }

    public function refreshSyncProgress(): void
    {
        $this->refreshIncrementalSyncProgress();
        $this->refreshKeywordResyncProgress();
    }

    private function restoreIncrementalSyncProgressFromCache(): void
    {
        $this->refreshIncrementalSyncProgress();
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     */
    private function applyIncrementalSyncProgress(array $progress): void
    {
        $this->incrementalSyncProgress = (int) $progress['done'];
        $this->incrementalSyncTotal = (int) $progress['total'];
        $this->incrementalSyncRunning = (bool) $progress['running'];

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $state = Cache::get(IncrementalDomainSyncCache::cacheKey($userId, $siteId));
        $this->incrementalSyncResumable = IncrementalDomainSyncCache::isResumable(is_array($state) ? $state : null);

        $this->dispatch(
            'incremental-sync-progress',
            done: $this->incrementalSyncProgress,
            total: $this->incrementalSyncTotal,
            running: $this->incrementalSyncRunning,
        );
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return __('Overview').': '.$site->domain;
    }

    public function getSite(): Site
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return $site;
    }

    public function isSiteSynced(): bool
    {
        return app(DomainOverviewService::class)->isSiteSynced((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getIncrementalSyncCacheState(): ?array
    {
        $state = Cache::get(IncrementalDomainSyncCache::cacheKey(
            (int) auth()->id(),
            (int) $this->getRecord()->getKey(),
        ));

        return is_array($state) ? $state : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getApiTokenSummary(): array
    {
        return app(DomainOverviewService::class)->getApiTokenSummary($this->getSite());
    }

    /**
     * @return array{read_token: string, migration_token: string}
     */
    public function getPlainTokens(): array
    {
        return app(DomainOverviewService::class)->getApiTokensPlain($this->getSite());
    }

    public function toggleTokenVisibility(string $field): void
    {
        if (! $this->tokensUnlocked) {
            $this->pendingRevealField = in_array($field, ['read', 'migration'], true) ? $field : 'read';
            $this->showPasswordPrompt = true;
            $this->tokenPassword = '';

            return;
        }

        if ($field === 'migration') {
            $this->migrationTokenVisible = ! $this->migrationTokenVisible;

            return;
        }

        $this->readTokenVisible = ! $this->readTokenVisible;
    }

    public function cancelPasswordPrompt(): void
    {
        $this->showPasswordPrompt = false;
        $this->tokenPassword = '';
        $this->pendingRevealField = null;
    }

    public function confirmRevealTokens(): void
    {
        $user = auth()->user();
        if ($user === null) {
            throw ValidationException::withMessages([
                'tokenPassword' => 'You need to sign in to view tokens.',
            ]);
        }

        if (! Hash::check($this->tokenPassword, $user->password)) {
            throw ValidationException::withMessages([
                'tokenPassword' => 'Incorrect password.',
            ]);
        }

        session(['seo_domain_tokens_verified' => true]);
        $this->tokensUnlocked = true;
        $this->showPasswordPrompt = false;
        $this->applyPendingTokenVisibility();
        $this->tokenPassword = '';
    }

    public function getInternalLinkTabUrl(string $tab): string
    {
        return static::getUrl(['record' => $this->getRecord()]).'?tab='.urlencode($tab);
    }

    public function getArticlesFilterUrl(string $band): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrl(
            (int) $this->getRecord()->getKey(),
            $band,
        );
    }

    public function getArticlesFilterUrlForLink(string $url, string $type): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForLink(
            (int) $this->getRecord()->getKey(),
            $url,
            $type,
        );
    }

    public function getArticlesFilterUrlForKeyword(int $keywordId): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForKeyword(
            (int) $this->getRecord()->getKey(),
            $keywordId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getScoringStatistics(): array
    {
        return app(DomainOverviewService::class)->getScoringStatistics((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getScoreDistribution(): array
    {
        return app(DomainOverviewService::class)->getScoreDistribution((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getSyncStatistics(): array
    {
        return app(DomainOverviewService::class)->getSyncStatistics((int) $this->getRecord()->getKey());
    }

    /**
     * @return Collection<int, object>
     */
    public function getTopKeywords(): Collection
    {
        return app(DomainOverviewService::class)->getTopKeywords((int) $this->getRecord()->getKey());
    }

    /**
     * @return Collection<int, object>
     */
    public function getTopLinks(): Collection
    {
        return app(DomainOverviewService::class)->getTopLinks((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getTechnicalSeoSummary(): array
    {
        return app(DomainOverviewService::class)->getTechnicalSeoSummary($this->getSite());
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->deleteDomainAction(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getActions(): array
    {
        return [
            $this->deleteDomainAction(),
            $this->syncIncrementalAction(),
            $this->resyncKeywordsAction(),
            $this->testSyncDataAction(),
        ];
    }

    protected function deleteDomainAction(): Action
    {
        return Action::make('delete_domain')
            ->label(__('seo-content-ai::filament.domain.delete_domain'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('seo-content-ai::filament.domain.delete_domain_heading'))
            ->modalDescription(__('seo-content-ai::filament.domain.delete_domain_description'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.domain.delete_domain_submit'))
            ->action(function (): void {
                /** @var Site $site */
                $site = $this->getRecord();

                app(ClearDomainArticlesService::class)->clear($site);

                $userId = (int) auth()->id();
                $siteId = (int) $site->getKey();
                Cache::forget(IncrementalDomainSyncCache::cacheKey($userId, $siteId));
                Cache::forget(IncrementalDomainSyncCache::fullItemsCacheKey(
                    IncrementalDomainSyncCache::cacheKey($userId, $siteId),
                ));

                $site->delete();

                Notification::make()
                    ->title(__('seo-content-ai::filament.domain.delete_domain_success'))
                    ->success()
                    ->send();

                $this->redirect(DomainResource::getUrl('index'), navigate: false);
            });
    }

    protected function syncIncrementalAction(): Action
    {
        return Action::make('sync_incremental')
            ->label(__('seo-content-ai::filament.domain.sync_incremental'))
            ->color('success')
            ->icon('heroicon-o-arrow-down-tray')
            ->action(function (): void {
                $this->runIncrementalSyncAction();
            });
    }

    protected function resyncKeywordsAction(): Action
    {
        return Action::make('resync_keywords')
            ->label(__('seo-content-ai::filament.keyword.resync_linked'))
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->visible(fn (): bool => SeoAccessControl::canMutateInSeoPanel())
            ->requiresConfirmation()
            ->modalHeading(__('seo-content-ai::filament.keyword.resync_linked'))
            ->modalDescription(__('seo-content-ai::filament.keyword.resync_linked_confirm'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.resync_linked_submit'))
            ->action(function (): void {
                $this->runRescrapeKeywordsAction();
            });
    }

    protected function testSyncDataAction(): Action
    {
        return Action::make('test_sync_data')
            ->label(__('seo-content-ai::filament.domain.test_sync_debug'))
            ->icon('heroicon-o-bug-ant')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->role === 'admin'
                && ! SeoAccessControl::isSeoPanelReadOnly())
            ->requiresConfirmation()
            ->modalDescription(__('seo-content-ai::filament.domain.test_sync_debug_description'))
            ->action(function (): void {
                $this->runDomainSyncTest();
            });
    }

    private function applyPendingTokenVisibility(): void
    {
        if ($this->pendingRevealField === 'migration') {
            $this->migrationTokenVisible = true;
        } elseif ($this->pendingRevealField === 'read') {
            $this->readTokenVisible = true;
        }

        $this->pendingRevealField = null;
    }

    private function canRevealTokensWithoutPassword(): bool
    {
        if (auth('sanctum')->check()) {
            return true;
        }

        return (bool) session('seo_domain_tokens_verified', false);
    }

    public function runIncrementalSyncAction(): void
    {
        @set_time_limit(120);

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $runner = app(IncrementalDomainSyncRunner::class);

        if ($runner->isRunning($userId, $siteId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_incremental_already_running'))
                ->warning()
                ->send();

            return;
        }

        $cacheKey = IncrementalDomainSyncCache::cacheKey($userId, $siteId);
        $cachedState = $this->getIncrementalSyncCacheState();

        if (IncrementalDomainSyncCache::isResumable($cachedState)) {
            $resumingState = IncrementalDomainSyncCache::markResuming($cachedState);
            Cache::put($cacheKey, $resumingState, now()->addHours(2));

            $progress = IncrementalDomainSyncCache::progressFromState($resumingState);
            $this->applyIncrementalSyncProgress($progress);

            RunIncrementalDomainSyncJob::dispatch($siteId, $userId);

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_incremental_resumed'))
                ->body(__('seo-content-ai::filament.domain.sync_incremental_resumed_hint', [
                    'done' => $progress['done'],
                    'total' => $progress['total'],
                ]))
                ->info()
                ->send();

            return;
        }

        /** @var Site $site */
        $site = $this->getRecord();
        $service = app(SyncDomainContentService::class);
        $prepared = $service->prepareIncrementalSync($site);

        if (! ($prepared['success'] ?? false)) {
            $this->notifySyncResult(
                $prepared,
                __('seo-content-ai::filament.domain.sync_incremental_success'),
                __('seo-content-ai::filament.domain.sync_incremental_failed'),
            );

            return;
        }

        $refs = is_array($prepared['refs'] ?? null) ? $prepared['refs'] : [];
        if ($refs === []) {
            $this->notifySyncResult(
                $prepared,
                __('seo-content-ai::filament.domain.sync_incremental_success'),
                __('seo-content-ai::filament.domain.sync_incremental_failed'),
            );

            return;
        }

        Cache::put($cacheKey, IncrementalDomainSyncCache::initialState($prepared, $refs), now()->addHours(2));
        Cache::forget(IncrementalDomainSyncCache::fullItemsCacheKey($cacheKey));

        $total = count($refs);
        $this->incrementalSyncTotal = $total;
        $this->incrementalSyncProgress = (int) ($prepared['skipped'] ?? 0);
        $this->incrementalSyncRunning = true;
        $this->incrementalSyncResumable = false;

        $manifestTotal = (int) ($prepared['manifest_total'] ?? $total);
        $initialDone = min($manifestTotal, (int) ($prepared['skipped'] ?? 0));

        $this->dispatch('incremental-sync-progress', done: $initialDone, total: $manifestTotal > 0 ? $manifestTotal : $total, running: true);

        RunIncrementalDomainSyncJob::dispatch($siteId, $userId);

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.sync_incremental_started'))
            ->body(__('seo-content-ai::filament.domain.sync_incremental_started_hint', [
                'total' => $manifestTotal > 0 ? $manifestTotal : $total,
            ]))
            ->info()
            ->send();
    }

    public function runRescrapeKeywordsAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        $userId = (int) auth()->id();

        if ($siteId <= 0 || $userId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.resync_linked_failed'))
                ->body(__('seo-content-ai::filament.keyword.resync_linked_no_domain'))
                ->warning()
                ->send();

            return;
        }

        KeywordDomainResyncCache::clearIfStale($userId, $siteId);

        if (
            KeywordDomainResyncCache::isRunning($userId, $siteId)
            || $this->keywordResyncRunning
        ) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.resync_linked_running'))
                ->body(__('seo-content-ai::filament.keyword.resync_linked_running_hint'))
                ->warning()
                ->send();

            return;
        }

        try {
            RunKeywordDomainResyncJob::dispatch($siteId, $userId);
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.resync_linked_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        KeywordDomainResyncCache::markRunning($userId, $siteId);
        $this->keywordResyncRunning = true;

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.resync_linked_started'))
            ->body(__('seo-content-ai::filament.keyword.resync_linked_started_hint'))
            ->info()
            ->send();
    }

    public function runAuditLinkStatusAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();

        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_failed'))
                ->body(__('seo-content-ai::filament.keyword.resync_linked_no_domain'))
                ->warning()
                ->send();

            return;
        }

        try {
            app(SeoDatabaseConnectionService::class)->bootstrapSeoDatabaseConnection($siteId);
            $queued = app(LinkMapStatusAuditService::class)->queueDomainAudit($siteId);
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($queued === 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_empty'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.audit_link_status_started', ['count' => $queued]))
            ->body(__('seo-content-ai::filament.domain.audit_link_status_started_hint'))
            ->info()
            ->send();
    }

    private function runDomainSyncTest(): void
    {
        /** @var Site $site */
        $site = $this->getRecord();

        $result = app(SyncDomainContentService::class)->sync($site, [
            'is_test' => true,
            'limit_per_type' => 2,
        ]);

        $this->notifySyncResult(
            $result,
            __('seo-content-ai::filament.domain.test_sync_debug_success'),
            __('seo-content-ai::filament.domain.test_sync_debug_failed'),
        );
    }

    /**
     * @param  array{success:bool,message:string}  $result
     */
    private function notifySyncResult(array $result, string $successTitle, string $failureTitle): void
    {
        if ($result['success']) {
            Notification::make()
                ->title($successTitle)
                ->body((string) ($result['message'] ?? ''))
                ->success()
                ->persistent()
                ->send();

            $this->dispatch('domain-sync-completed');

            return;
        }

        Notification::make()
            ->title($failureTitle)
            ->body((string) ($result['message'] ?? ''))
            ->danger()
            ->persistent()
            ->send();
    }
}
