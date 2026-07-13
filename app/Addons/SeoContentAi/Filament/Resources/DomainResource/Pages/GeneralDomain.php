<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Jobs\RunIncrementalDomainSyncJob;
use App\Addons\SeoContentAi\Jobs\RunKeywordDomainResyncJob;
use App\Addons\SeoContentAi\Jobs\RunMetadataDomainSyncJob;
use App\Addons\SeoContentAi\Services\ClearDomainArticlesService;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use App\Addons\SeoContentAi\Services\IncrementalDomainSyncRunner;
use App\Addons\SeoContentAi\Services\LinkMapStatusAuditService;
use App\Addons\SeoContentAi\Services\MetadataDomainSyncRunner;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Services\SyncDomainContentService;
use App\Addons\SeoContentAi\Support\IncrementalDomainSyncCache;
use App\Addons\SeoContentAi\Support\KeywordDomainResyncCache;
use App\Addons\SeoContentAi\Support\MetadataDomainSyncCache;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use App\Services\ExternalPlugin\ExternalPluginRegistry;
use App\Services\ExternalPlugin\WordPressPluginReleaseService;
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

    public int $metadataSyncProgress = 0;

    public int $metadataSyncTotal = 0;

    public bool $metadataSyncRunning = false;

    public bool $metadataSyncResumable = false;

    public bool $keywordResyncRunning = false;

    public string $incrementalSyncStatus = 'idle';

    public ?string $incrementalSyncStatusMessage = null;

    public string $metadataSyncStatus = 'idle';

    public ?string $metadataSyncStatusMessage = null;

    public string $keywordResyncStatus = 'idle';

    public ?string $keywordResyncStatusMessage = null;

    public string $auditLinkStatus = 'idle';

    public ?string $auditLinkStatusMessage = null;

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
        $this->restoreMetadataSyncProgressFromCache();
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
        $this->applyKeywordResyncStatus($progress);

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
        $this->refreshMetadataSyncProgress();
        $this->refreshKeywordResyncProgress();
    }

    private function restoreMetadataSyncProgressFromCache(): void
    {
        $this->refreshMetadataSyncProgress();
    }

    public function refreshMetadataSyncProgress(): void
    {
        $wasRunning = $this->metadataSyncRunning;

        $this->applyMetadataSyncProgress(
            app(MetadataDomainSyncRunner::class)->readProgress(
                (int) auth()->id(),
                (int) $this->getRecord()->getKey(),
            ),
        );

        if ($wasRunning && ! $this->metadataSyncRunning && $this->metadataSyncTotal > 0) {
            $this->dispatch('domain-sync-completed');
        }
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     */
    private function applyMetadataSyncProgress(array $progress): void
    {
        $this->metadataSyncProgress = (int) $progress['done'];
        $this->metadataSyncTotal = (int) $progress['total'];
        $this->metadataSyncRunning = (bool) $progress['running'];

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $state = Cache::get(MetadataDomainSyncCache::cacheKey($userId, $siteId));
        $this->metadataSyncResumable = MetadataDomainSyncCache::isResumable(is_array($state) ? $state : null);
        $this->applyMetadataSyncStatus($progress, is_array($state) ? $state : null);

        $this->dispatch(
            'metadata-sync-progress',
            done: $this->metadataSyncProgress,
            total: $this->metadataSyncTotal,
            running: $this->metadataSyncRunning,
        );
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
        $this->applyIncrementalSyncStatus($progress, is_array($state) ? $state : null);

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
    private function getMetadataSyncCacheState(): ?array
    {
        $state = Cache::get(MetadataDomainSyncCache::cacheKey(
            (int) auth()->id(),
            (int) $this->getRecord()->getKey(),
        ));

        return is_array($state) ? $state : null;
    }

    private function anyDomainSyncJobRunning(int $userId, int $siteId): bool
    {
        return app(IncrementalDomainSyncRunner::class)->isRunning($userId, $siteId)
            || app(MetadataDomainSyncRunner::class)->isRunning($userId, $siteId)
            || KeywordDomainResyncCache::isRunning($userId, $siteId);
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
    public function getWpPluginReleaseOverview(): array
    {
        try {
            $manifest = app(ExternalPluginRegistry::class)->resolveOrFail('omi-seo-ai-bridge');

            return WordPressPluginReleaseService::forManifest($manifest)->overview();
        } catch (\Throwable) {
            return [
                'has_packages' => false,
                'latest' => null,
                'metadata' => [],
            ];
        }
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
                Cache::forget(MetadataDomainSyncCache::cacheKey($userId, $siteId));
                Cache::forget(MetadataDomainSyncCache::fullItemsCacheKey(
                    MetadataDomainSyncCache::cacheKey($userId, $siteId),
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

        if ($runner->isRunning($userId, $siteId) || app(MetadataDomainSyncRunner::class)->isRunning($userId, $siteId)) {
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
            $this->incrementalSyncStatus = 'completed';
            $this->incrementalSyncStatusMessage = (string) ($prepared['message'] ?? __('seo-content-ai::filament.domain.sync_incremental_success'));

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
        $this->incrementalSyncProgress = 0;
        $this->incrementalSyncRunning = true;
        $this->incrementalSyncResumable = false;
        $this->incrementalSyncStatus = 'running';
        $this->incrementalSyncStatusMessage = __('seo-content-ai::filament.domain.sync_incremental_progress', [
            'done' => 0,
            'total' => $total,
        ]);

        $this->dispatch('incremental-sync-progress', done: 0, total: $total, running: true);

        RunIncrementalDomainSyncJob::dispatch($siteId, $userId);

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.sync_incremental_started'))
            ->body(__('seo-content-ai::filament.domain.sync_incremental_started_hint', [
                'total' => $total,
            ]))
            ->info()
            ->send();
    }

    public function runMetadataResyncAction(): void
    {
        @set_time_limit(120);

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $runner = app(MetadataDomainSyncRunner::class);

        if ($this->anyDomainSyncJobRunning($userId, $siteId) || $this->metadataSyncRunning) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_metadata_already_running'))
                ->warning()
                ->send();

            return;
        }

        $cacheKey = MetadataDomainSyncCache::cacheKey($userId, $siteId);
        $cachedState = $this->getMetadataSyncCacheState();

        if (MetadataDomainSyncCache::isResumable($cachedState)) {
            $resumingState = MetadataDomainSyncCache::markResuming($cachedState);
            Cache::put($cacheKey, $resumingState, now()->addHours(2));

            $progress = MetadataDomainSyncCache::progressFromState($resumingState);
            $this->applyMetadataSyncProgress($progress);

            RunMetadataDomainSyncJob::dispatch($siteId, $userId);

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_metadata_resumed'))
                ->body(__('seo-content-ai::filament.domain.sync_metadata_resumed_hint', [
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
        $prepared = $service->prepareMetadataResync($site);

        if (! ($prepared['success'] ?? false)) {
            $this->notifySyncResult(
                $prepared,
                __('seo-content-ai::filament.domain.sync_metadata_success'),
                __('seo-content-ai::filament.domain.sync_metadata_failed'),
            );

            return;
        }

        $refs = is_array($prepared['refs'] ?? null) ? $prepared['refs'] : [];
        if ($refs === []) {
            $this->metadataSyncStatus = 'completed';
            $this->metadataSyncStatusMessage = (string) ($prepared['message'] ?? __('seo-content-ai::filament.domain.sync_metadata_success'));

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_metadata_success'))
                ->body((string) ($prepared['message'] ?? ''))
                ->success()
                ->send();

            return;
        }

        Cache::put($cacheKey, MetadataDomainSyncCache::initialState($prepared, $refs), now()->addHours(2));
        Cache::forget(MetadataDomainSyncCache::fullItemsCacheKey($cacheKey));

        $total = count($refs);
        $this->metadataSyncTotal = $total;
        $this->metadataSyncProgress = 0;
        $this->metadataSyncRunning = true;
        $this->metadataSyncResumable = false;
        $this->metadataSyncStatus = 'running';
        $this->metadataSyncStatusMessage = __('seo-content-ai::filament.domain.sync_metadata_progress', [
            'done' => 0,
            'total' => $total,
        ]);

        $this->dispatch('metadata-sync-progress', done: 0, total: $total, running: true);

        RunMetadataDomainSyncJob::dispatch($siteId, $userId);

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.sync_metadata_started'))
            ->body(__('seo-content-ai::filament.domain.sync_metadata_started_hint', [
                'total' => $total,
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
            || app(IncrementalDomainSyncRunner::class)->isRunning($userId, $siteId)
            || app(MetadataDomainSyncRunner::class)->isRunning($userId, $siteId)
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
        $this->keywordResyncStatus = 'running';
        $this->keywordResyncStatusMessage = __('seo-content-ai::filament.keyword.resync_linked_running');

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.resync_linked_started'))
            ->body(__('seo-content-ai::filament.keyword.resync_linked_started_hint'))
            ->info()
            ->send();
    }

    public function getSeoScoringProgress(): array
    {
        return app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
            ->domainProgress((int) $this->getRecord()->getKey());
    }

    public function runQueueMissingSeoScoringAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        if ($siteId <= 0) {
            return;
        }

        try {
            app(SeoDatabaseConnectionService::class)->bootstrapSeoDatabaseConnection($siteId);
            $result = app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
                ->queueMissingForSite($siteId);
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.seo_scoring_queue_missing'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.seo_scoring_queue_missing'))
            ->body(__('seo-content-ai::filament.domain.seo_scoring_queue_missing_success', [
                'count' => $result['queued'],
            ]))
            ->success()
            ->send();
    }

    public function runRetryFailedSeoScoringAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        if ($siteId <= 0) {
            return;
        }

        try {
            app(SeoDatabaseConnectionService::class)->bootstrapSeoDatabaseConnection($siteId);
            $result = app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
                ->queueFailedForSite($siteId);
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.seo_scoring_retry_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.seo_scoring_retry_failed'))
            ->body(__('seo-content-ai::filament.domain.seo_scoring_retry_failed_success', [
                'count' => $result['queued'],
            ]))
            ->success()
            ->send();
    }

    public function runRequeueAllSeoScoringAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        if ($siteId <= 0) {
            return;
        }

        try {
            app(SeoDatabaseConnectionService::class)->bootstrapSeoDatabaseConnection($siteId);
            $result = app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
                ->queueAllForSite($siteId);
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.seo_scoring_requeue_all'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.seo_scoring_requeue_all'))
            ->body(__('seo-content-ai::filament.domain.seo_scoring_requeue_all_success', [
                'count' => $result['queued'],
            ]))
            ->success()
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

            $this->auditLinkStatus = 'failed';
            $this->auditLinkStatusMessage = $exception->getMessage();

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($queued === 0) {
            $this->auditLinkStatus = 'failed';
            $this->auditLinkStatusMessage = __('seo-content-ai::filament.domain.audit_link_status_empty');

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_empty'))
                ->warning()
                ->send();

            return;
        }

        $this->auditLinkStatus = 'completed';
        $this->auditLinkStatusMessage = __('seo-content-ai::filament.domain.audit_link_status_started', [
            'count' => $queued,
        ]);

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
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     * @param  array<string, mixed>|null  $state
     */
    private function applyIncrementalSyncStatus(array $progress, ?array $state): void
    {
        [$status, $message] = $this->resolveChunkedJobStatus(
            progress: $progress,
            state: $state,
            isResumable: static fn (?array $cachedState): bool => IncrementalDomainSyncCache::isResumable($cachedState),
            progressLabel: __('seo-content-ai::filament.domain.sync_incremental_progress', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
            successLabel: __('seo-content-ai::filament.domain.sync_incremental_success'),
            failedLabel: __('seo-content-ai::filament.domain.sync_incremental_failed'),
            resumableLabel: __('seo-content-ai::filament.domain.sync_incremental_resumed_hint', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
        );

        $this->incrementalSyncStatus = $status;
        $this->incrementalSyncStatusMessage = $message;
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     * @param  array<string, mixed>|null  $state
     */
    private function applyMetadataSyncStatus(array $progress, ?array $state): void
    {
        [$status, $message] = $this->resolveChunkedJobStatus(
            progress: $progress,
            state: $state,
            isResumable: static fn (?array $cachedState): bool => MetadataDomainSyncCache::isResumable($cachedState),
            progressLabel: __('seo-content-ai::filament.domain.sync_metadata_progress', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
            successLabel: __('seo-content-ai::filament.domain.sync_metadata_success'),
            failedLabel: __('seo-content-ai::filament.domain.sync_metadata_failed'),
            resumableLabel: __('seo-content-ai::filament.domain.sync_metadata_resumed_hint', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
        );

        $this->metadataSyncStatus = $status;
        $this->metadataSyncStatusMessage = $message;
    }

    /**
     * @param  array{running: bool, status: string, message: ?string, result: ?array<string, mixed>}  $progress
     */
    private function applyKeywordResyncStatus(array $progress): void
    {
        if ($progress['running']) {
            $this->keywordResyncStatus = 'running';
            $this->keywordResyncStatusMessage = __('seo-content-ai::filament.keyword.resync_linked_running');

            return;
        }

        $status = (string) ($progress['status'] ?? '');

        if ($status === KeywordDomainResyncCache::STATUS_COMPLETED) {
            $this->keywordResyncStatus = 'completed';
            $this->keywordResyncStatusMessage = __('seo-content-ai::filament.keyword.resync_linked_completed');

            return;
        }

        if ($status === KeywordDomainResyncCache::STATUS_FAILED) {
            $this->keywordResyncStatus = 'failed';
            $this->keywordResyncStatusMessage = (string) ($progress['message'] ?? __('seo-content-ai::filament.keyword.resync_linked_failed'));

            return;
        }

        $this->keywordResyncStatus = 'idle';
        $this->keywordResyncStatusMessage = __('seo-content-ai::filament.domain.sync_action_status_ready');
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     * @param  array<string, mixed>|null  $state
     * @return array{0: string, 1: ?string}
     */
    private function resolveChunkedJobStatus(
        array $progress,
        ?array $state,
        callable $isResumable,
        string $progressLabel,
        string $successLabel,
        string $failedLabel,
        string $resumableLabel,
    ): array {
        if ($progress['running']) {
            return ['running', $progressLabel];
        }

        if ($isResumable($state)) {
            return ['resumable', $resumableLabel];
        }

        $status = (string) ($progress['status'] ?? '');

        if ($status === IncrementalDomainSyncCache::STATUS_COMPLETED) {
            $message = trim((string) (($state['message'] ?? null) ?: $successLabel));

            return ['completed', $message !== '' ? $message : $successLabel];
        }

        if ($status === IncrementalDomainSyncCache::STATUS_FAILED) {
            $message = trim((string) ($progress['message'] ?? $state['message'] ?? $failedLabel));

            return ['failed', $message !== '' ? $message : $failedLabel];
        }

        return ['idle', __('seo-content-ai::filament.domain.sync_action_status_ready')];
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
