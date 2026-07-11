<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\GscQueriesTableService;
use App\Addons\SeoContentAi\Services\GoogleSearchConsoleBulkSyncService;
use App\Addons\SeoContentAi\Services\GoogleSearchConsoleConnectionService;
use App\Addons\SeoContentAi\Services\GoogleSearchConsoleSyncService;
use App\Addons\SeoContentAi\Services\SeoPerformanceDashboardService;
use App\Addons\SeoContentAi\Services\SeoPerformanceHubService;
use App\Addons\SeoContentAi\Services\KeywordRankComparisonResultService;
use App\Addons\SeoContentAi\Services\KeywordRankComparisonService;
use App\Addons\SeoContentAi\Services\SeoSerpProviderConnectionService;
use App\Addons\SeoContentAi\Support\SerpProviderKeys;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

final class SeoPerformanceHub extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'SEO Performance';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'performance-hub';

    protected static string $view = 'seo-content-ai::seo.performance-hub';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'source')]
    public string $dataSource = '';

    #[Url(as: 'tab')]
    public string $activeTab = 'queries';

    #[Url(as: 'sort')]
    public string $querySortBy = 'impressions';

    #[Url(as: 'dir')]
    public string $querySortDir = 'desc';

    #[Url(as: 'q')]
    public string $keywordSearch = '';

    #[Url(as: 'position_bucket')]
    public string $positionBucket = '';

    #[Url(as: 'gsc_page')]
    public int $gscPage = 1;

    #[Url(as: 'gsc_per_page')]
    public int $gscPerPage = GscQueriesTableService::DEFAULT_PER_PAGE;

    #[Url(as: 'gsc_q')]
    public string $gscQuerySearch = '';

    #[Url(as: 'gsc_metric')]
    public string $gscChartMetric = 'clicks';

    public string $dateRange = '28d';

    public string $device = 'all';

    public string $location = '';

    public bool $isGscBulkSyncing = false;

    /** @var array<string, mixed>|null */
    public ?array $gscBulkSyncResult = null;

    #[Url(as: 'comparison_batch')]
    public string $comparisonBatchId = '';

    public string $comparisonKeyword = '';

    /** @var list<string> */
    public array $comparisonProviders = [];

    private SeoPerformanceHubService $performanceHub;

    private SeoPerformanceDashboardService $dashboard;

    private GoogleSearchConsoleSyncService $gscSync;

    private GoogleSearchConsoleBulkSyncService $gscBulkSync;

    private GoogleSearchConsoleConnectionService $gscConnection;

    private GscQueriesTableService $gscQueriesTable;

    private SeoSerpProviderConnectionService $serpConnections;

    private KeywordRankComparisonService $rankComparison;

    private KeywordRankComparisonResultService $comparisonResults;

    private int $lastResolvedSiteId = 0;

    public function boot(
        SeoPerformanceHubService $performanceHub,
        SeoPerformanceDashboardService $dashboard,
        GoogleSearchConsoleSyncService $gscSync,
        GoogleSearchConsoleBulkSyncService $gscBulkSync,
        GoogleSearchConsoleConnectionService $gscConnection,
        GscQueriesTableService $gscQueriesTable,
        SeoSerpProviderConnectionService $serpConnections,
        KeywordRankComparisonService $rankComparison,
        KeywordRankComparisonResultService $comparisonResults,
    ): void {
        $this->performanceHub = $performanceHub;
        $this->dashboard = $dashboard;
        $this->gscSync = $gscSync;
        $this->gscBulkSync = $gscBulkSync;
        $this->gscConnection = $gscConnection;
        $this->gscQueriesTable = $gscQueriesTable;
        $this->serpConnections = $serpConnections;
        $this->rankComparison = $rankComparison;
        $this->comparisonResults = $comparisonResults;
    }

    public function booted(): void
    {
        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($this->lastResolvedSiteId > 0 && $this->lastResolvedSiteId !== $siteId) {
            $this->resetGscTableState();
        }

        $this->lastResolvedSiteId = $siteId;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public function mount(): void
    {
        if ($this->activeTab === 'ai-discovery') {
            $this->redirect(AiKeywordDiscovery::getUrl());

            return;
        }

        if ($this->activeTab === 'cannibalization') {
            $this->redirect(KeywordResource::getUrl('cannibalization'));

            return;
        }

        if (! in_array($this->dateRange, ['7d', '28d', '90d'], true)) {
            $this->dateRange = '28d';
        }

        if (! in_array($this->device, ['all', 'desktop', 'mobile', 'tablet'], true)) {
            $this->device = 'all';
        }

        if ($this->dataSource === '') {
            $this->dataSource = $this->dashboard->resolveDefaultDataSource($this->resolveSiteId());
        } else {
            $this->dataSource = $this->dashboard->resolveSourceOrFallback(
                $this->dataSource,
                (int) auth()->id(),
                $this->resolveSiteId(),
            );
        }

        $this->positionBucket = (string) ($this->gscQueriesTable->normalizePositionBucket($this->positionBucket) ?? '');
        $this->gscPerPage = $this->gscQueriesTable->normalizePerPage($this->gscPerPage);
        $this->gscPage = max(1, $this->gscPage);
        $this->gscChartMetric = $this->normalizeGscChartMetric($this->gscChartMetric);

        $this->normalizeActiveTab();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.performance_hub.title');
    }

    public function setDataSource(string $source): void
    {
        $allowed = ['gsc', ...SerpProviderKeys::all()];
        if (! in_array($source, $allowed, true)) {
            return;
        }

        if ($source !== 'gsc') {
            $connection = $this->serpConnections->resolveForUser((int) auth()->id(), $source);
            if ($connection === null || ! $connection->isConfigured()) {
                return;
            }
        }

        $this->dataSource = $source;
        $this->normalizeActiveTab();
        if ($source !== 'gsc') {
            return;
        }

        $this->gscPage = 1;
    }

    public function setRankPositionBucket(string $bucket): void
    {
        $allowed = ['1-3', '4-10', '11-20', '21-50', '51-100'];
        if (! in_array($bucket, $allowed, true)) {
            return;
        }

        $this->positionBucket = $this->positionBucket === $bucket ? '' : $bucket;
    }

    public function clearRankPositionBucket(): void
    {
        $this->positionBucket = '';
    }

    public function testSerpConnection(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel() || ! $this->isRankProviderSource()) {
            return;
        }

        $connection = $this->serpConnections->resolveForUser((int) auth()->id(), $this->dataSource);
        if ($connection === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.serp_not_configured'))
                ->warning()
                ->send();

            return;
        }

        $result = $this->serpConnections->testConnection($connection);
        Notification::make()
            ->title(($result['ok'] ?? false)
                ? __('seo-content-ai::filament.api_connections.test_success')
                : __('seo-content-ai::filament.api_connections.test_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->{($result['ok'] ?? false) ? 'success' : 'danger'}()
            ->send();
    }

    public function runComparisonCheck(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures() || ! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        $providers = $this->comparisonProviders !== []
            ? $this->comparisonProviders
            : array_map(
                static fn (array $tab): string => (string) $tab['key'],
                $this->serpConnections->tabSourcesForUser((int) auth()->id()),
            );

        try {
            $result = $this->rankComparison->dispatchComparison(
                siteId: $siteId,
                userId: (int) auth()->id(),
                providers: $providers,
                keywordPhrase: $this->comparisonKeyword !== '' ? $this->comparisonKeyword : null,
                country: null,
                location: $this->location !== '' ? $this->location : null,
                language: null,
                device: $this->device !== 'all' ? $this->device : null,
            );
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.comparison_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->comparisonBatchId = (string) ($result['batch_id'] ?? '');
        Notification::make()
            ->title(__('seo-content-ai::filament.performance_hub.comparison_queued'))
            ->body(__('seo-content-ai::filament.performance_hub.comparison_queued_body', [
                'count' => (int) ($result['job_count'] ?? 0),
            ]))
            ->success()
            ->send();
    }

    public function setActiveTab(string $tab): void
    {
        $allowed = $this->dataSource === 'gsc'
            ? ['queries', 'quick-wins', 'pages']
            : ['rankings', 'serp-changes'];

        if (! in_array($tab, $allowed, true)) {
            return;
        }

        if ($this->activeTab !== $tab) {
            $this->gscPage = 1;
        }

        $this->activeTab = $tab;
    }

    public function setPositionBucket(string $bucket): void
    {
        $normalized = $this->gscQueriesTable->normalizePositionBucket($bucket);
        if ($normalized === null) {
            return;
        }

        $this->positionBucket = $this->positionBucket === $normalized ? '' : $normalized;
        $this->gscPage = 1;
    }

    public function clearPositionBucket(): void
    {
        $this->positionBucket = '';
        $this->gscPage = 1;
    }

    public function gotoGscPage(int $page): void
    {
        $this->gscPage = max(1, $page);
    }

    public function setGscPerPage(int $perPage): void
    {
        $this->gscPerPage = $this->gscQueriesTable->normalizePerPage($perPage);
        $this->gscPage = 1;
    }

    public function setGscChartMetric(string $metric): void
    {
        $this->gscChartMetric = $this->normalizeGscChartMetric($metric);
    }

    public function updatedGscQuerySearch(): void
    {
        $this->gscPage = 1;
    }

    public function sortGscQueries(string $column): void
    {
        if ($this->querySortBy === $column) {
            $this->querySortDir = $this->querySortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->querySortBy = $column;
            $this->querySortDir = 'desc';
        }

        $this->gscPage = 1;
    }

    public function syncGscData(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        $gscStatus = $this->gscConnection->statusForSite($siteId);
        $connectionId = (int) ($gscStatus['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            $connection = $this->gscConnection->resolveForSite($siteId);
            $connectionId = (int) ($connection?->id ?? 0);
        }

        if ($connectionId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_sync_failed'))
                ->body(__('seo-content-ai::filament.api_connections.not_configured'))
                ->warning()
                ->send();

            return;
        }

        $mapResult = $this->gscBulkSync->ensureSiteMapped($siteId, $connectionId, (int) auth()->id());
        if (! $mapResult['ok']) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_sync_failed'))
                ->body((string) ($mapResult['message'] ?? __('seo-content-ai::filament.api_connections.gsc_mapping_missing')))
                ->warning()
                ->send();

            return;
        }

        $result = $this->gscSync->syncSiteWithDetails($siteId, (int) auth()->id());
        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_sync_success')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    public function syncAllMappedGscDomains(): void
    {
        if ($this->isGscBulkSyncing) {
            return;
        }

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $this->isGscBulkSyncing = true;

        $siteId = $this->resolveSiteId();
        $gscStatus = $this->gscConnection->statusForSite($siteId);
        $connectionId = (int) ($gscStatus['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            $connection = $this->gscConnection->resolveForSite($siteId);
            $connectionId = (int) ($connection?->id ?? 0);
        }

        if ($connectionId <= 0) {
            $this->isGscBulkSyncing = false;
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_sync_failed'))
                ->body(__('seo-content-ai::filament.api_connections.not_configured'))
                ->warning()
                ->send();

            return;
        }

        $result = $this->gscBulkSync->autoMapAndSyncAll((int) auth()->id(), $connectionId, queueSync: false);
        $this->gscBulkSyncResult = $result;
        $this->isGscBulkSyncing = false;

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_bulk_sync_complete')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'] ?? '')
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    public function retryGscSyncForSite(int $siteId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel() || ! SeoAccessControl::canAccessSite($siteId)) {
            return;
        }

        $result = $this->gscSync->syncSiteWithDetails($siteId, (int) auth()->id());
        if ($this->gscBulkSyncResult !== null && is_array($this->gscBulkSyncResult['rows'] ?? null)) {
            foreach ($this->gscBulkSyncResult['rows'] as $index => $row) {
                if ((int) ($row['site_id'] ?? 0) !== $siteId) {
                    continue;
                }

                $this->gscBulkSyncResult['rows'][$index]['sync_status'] = $result['ok']
                    ? (($result['query_count'] ?? 0) === 0 ? 'empty_success' : 'synced')
                    : 'failed';
                $this->gscBulkSyncResult['rows'][$index]['error'] = $result['ok'] ? null : $result['message'];
            }
        }

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_sync_success')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    public function runKeywordRankCheck(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        try {
            $result = $this->dashboard->dispatchRankCheck(
                siteId: $siteId,
                userId: (int) auth()->id(),
                provider: $this->dataSource,
                location: $this->location !== '' ? $this->location : null,
                language: null,
                device: $this->device !== 'all' ? $this->device : null,
            );
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.rank_check_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (($result['queued'] ?? false) === true) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.rank_check_queued'))
                ->body(__('seo-content-ai::filament.performance_hub.rank_check_queued_body', [
                    'count' => (int) ($result['keyword_count'] ?? 0),
                ]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.performance_hub.rank_check_failed'))
            ->warning()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function gscDashboardState(): array
    {
        if ($this->dataSource !== 'gsc') {
            return [];
        }

        return $this->dashboard->buildGscState(
            siteId: $this->resolveSiteId(),
            sortBy: $this->querySortBy,
            sortDir: $this->querySortDir,
            search: $this->gscQuerySearch,
            positionBucket: $this->positionBucket !== '' ? $this->positionBucket : null,
            page: $this->gscPage,
            perPage: $this->gscPerPage,
            chartMetric: $this->gscChartMetric,
        );
    }

    #[Computed]
    public function availableSourceTabs(): array
    {
        return $this->dashboard->availableSourceTabs((int) auth()->id());
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function rankDashboardState(): array
    {
        if (! $this->isRankProviderSource()) {
            return [];
        }

        return $this->dashboard->buildRankState(
            siteId: $this->resolveSiteId(),
            provider: $this->dataSource,
            keywordSearch: $this->keywordSearch,
            device: $this->device,
            location: $this->location,
            positionBucket: $this->positionBucket,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function comparisonRows(): array
    {
        if ($this->comparisonBatchId === '') {
            return [];
        }

        return $this->comparisonResults->buildRows($this->comparisonBatchId);
    }

    public function pushQuickWinToEditor(string $phrase, string $type = Keyword::TYPE_SUGGEST): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        $keyword = $this->performanceHub->pushKeywordToEditor($phrase, $siteId, $type);
        if (! $keyword instanceof Keyword) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.push_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.performance_hub.push_success', ['phrase' => $keyword->phrase]))
            ->success()
            ->send();
    }

    private function resetGscTableState(): void
    {
        $this->gscPage = 1;
        $this->positionBucket = '';
        $this->gscQuerySearch = '';
    }

    private function normalizeGscChartMetric(string $metric): string
    {
        return in_array($metric, ['clicks', 'impressions', 'ctr', 'position'], true)
            ? $metric
            : 'clicks';
    }

    private function normalizeActiveTab(): void
    {
        if ($this->dataSource === 'gsc') {
            if (! in_array($this->activeTab, ['queries', 'quick-wins', 'pages'], true)) {
                $this->activeTab = 'queries';
            }

            return;
        }

        if (! in_array($this->activeTab, ['rankings', 'serp-changes'], true)) {
            $this->activeTab = 'rankings';
        }
    }

    private function isRankProviderSource(): bool
    {
        return SerpProviderKeys::isValid($this->dataSource);
    }

    private function resolveSiteId(): ?int
    {
        return SeoAccessControl::globalSiteId();
    }
}
