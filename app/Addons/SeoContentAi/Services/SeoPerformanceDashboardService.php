<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Models\KeywordRankSnapshot;
use App\Addons\SeoContentAi\Support\SerpProviderKeys;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use Illuminate\Support\Collection;

final class SeoPerformanceDashboardService
{
    public function __construct(
        private readonly SeoPerformanceHubService $performanceHub,
        private readonly GscQueriesTableService $gscQueriesTable,
        private readonly DataForSeoConnectionService $dataForSeo,
        private readonly SeoSerpProviderConnectionService $serpConnections,
        private readonly GoogleSearchConsoleConnectionService $gscConnection,
        private readonly KeywordRankCheckService $rankCheckService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildGscState(
        ?int $siteId,
        string $sortBy = 'impressions',
        string $sortDir = 'desc',
        string $search = '',
        ?string $positionBucket = null,
        int $page = 1,
        int $perPage = GscQueriesTableService::DEFAULT_PER_PAGE,
        string $chartMetric = 'clicks',
    ): array {
        $gscKpis = $this->performanceHub->getGscKpis($siteId);
        $gscStatus = $this->gscConnection->statusForSite($siteId);
        $sourceQueries = $this->performanceHub->getGscQueriesSource($siteId);
        $tableState = $this->gscQueriesTable->buildTableState(
            queries: $sourceQueries,
            search: $search,
            positionBucket: $positionBucket,
            sortBy: $sortBy,
            sortDir: $sortDir,
            page: $page,
            perPage: $perPage,
        );

        return [
            'connection' => $gscStatus,
            'settings_url' => $gscStatus['gsc_edit_url'] ?? $this->resolveSettingsUrl(),
            'kpis' => $this->buildGscKpiCards($gscKpis, $this->performanceHub->getGscTotalQueries($siteId)),
            'distribution' => $this->performanceHub->getGscQueryDistribution($siteId),
            'chart' => $this->performanceHub->getGscPerformanceChart($siteId, $chartMetric),
            'queries' => $tableState['rows'],
            'queries_pagination' => $tableState['pagination'],
            'queries_total_filtered' => $tableState['total_filtered'],
            'queries_total_source' => $tableState['total_source'],
            'quick_wins' => $this->performanceHub->getQuickWinQueries($siteId),
            'has_data' => ($gscKpis['has_data'] ?? false) === true,
            'has_pages' => false,
            'position_bucket' => $this->gscQueriesTable->normalizePositionBucket($positionBucket),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRankState(
        ?int $siteId,
        string $provider,
        string $keywordSearch = '',
        string $device = 'all',
        string $location = '',
        string $positionBucket = '',
    ): array {
        if (! SerpProviderKeys::isValid($provider)) {
            throw new \InvalidArgumentException("Invalid rank provider: {$provider}");
        }

        $rankingRows = $this->buildRankingRows($siteId, $provider, $keywordSearch, $device, $location, $positionBucket);
        $distribution = $this->buildRankingDistribution($rankingRows);
        $providerStatus = $this->serpConnections->statusForUser((int) auth()->id(), $provider);

        return [
            'provider' => $provider,
            'connections' => [
                'provider' => $this->buildRankConnectionStrip($provider, $providerStatus),
                'settings_url' => $this->resolveSettingsUrl(),
            ],
            'kpis' => $this->buildRankKpiCards($rankingRows, $distribution, includeSearchVolume: false),
            'ranking_rows' => $rankingRows,
            'serp_changes' => $this->buildSerpChanges($siteId, $provider),
            'distribution' => $distribution,
            'visibility_chart' => $this->buildVisibilityChart($siteId, $provider),
            'has_rank_data' => $rankingRows !== [],
            'has_rank_provider' => ($providerStatus['configured'] ?? false) === true,
            'position_bucket' => $positionBucket,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildState(
        ?int $siteId,
        string $keywordSearch = '',
        string $sortBy = 'impressions',
        string $sortDir = 'desc',
        string $device = 'all',
        string $location = '',
    ): array {
        return [
            'gsc' => $this->buildGscState($siteId, $sortBy, $sortDir),
            'rank' => $this->buildRankState($siteId, SerpProviderKeys::SERPER),
        ];
    }

    public function resolveDefaultDataSource(?int $siteId): string
    {
        $gscStatus = $this->gscConnection->statusForSite($siteId);
        $hasGscReady = in_array($gscStatus['status'] ?? '', ['connected', 'sync_required'], true)
            && filled($gscStatus['property_url'] ?? null);

        if ($hasGscReady) {
            return 'gsc';
        }

        $tabs = $this->serpConnections->tabSourcesForUser((int) auth()->id());
        if ($tabs !== []) {
            return (string) $tabs[0]['key'];
        }

        return 'gsc';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableSourceTabs(int $userId): array
    {
        $tabs = [
            [
                'key' => 'gsc',
                'label' => __('seo-content-ai::filament.performance_hub.source_gsc'),
                'configured' => true,
                'active' => true,
            ],
        ];

        foreach ($this->serpConnections->tabSourcesForUser($userId) as $providerTab) {
            $tabs[] = $providerTab;
        }

        return $tabs;
    }

    public function resolveSourceOrFallback(string $requestedSource, int $userId, ?int $siteId): string
    {
        if ($requestedSource === 'gsc') {
            return 'gsc';
        }

        if (SerpProviderKeys::isValid($requestedSource)) {
            $connection = $this->serpConnections->resolveForUser($userId, $requestedSource);
            if ($connection !== null && $connection->isConfigured()) {
                return $requestedSource;
            }
        }

        return $this->resolveDefaultDataSource($siteId);
    }

    public function hasRankProvider(string $provider): bool
    {
        if (! SerpProviderKeys::isValid($provider)) {
            return false;
        }

        return $this->serpConnections->isConfiguredForUser((int) auth()->id(), $provider);
    }

    /**
     * @return array{queued: bool, keyword_count: int, run_id: int|null}
     */
    public function dispatchRankCheck(
        int $siteId,
        int $userId,
        string $provider,
        ?string $country = null,
        ?string $location = null,
        ?string $language = null,
        ?string $device = null,
    ): array {
        if (! SeoAccessControl::canAccessSite($siteId)) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.no_domain'));
        }

        if (! $this->serpConnections->isConfiguredForUser($userId, $provider)) {
            throw new \RuntimeException(__('seo-content-ai::filament.api_connections.serp_not_configured'));
        }

        return $this->rankCheckService->dispatchForSite(
            siteId: $siteId,
            userId: $userId,
            provider: $provider,
            country: $country,
            location: $location,
            language: $language,
            device: $device,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRankConnectionStrip(string $provider, array $providerStatus): array
    {
        return [
            'provider' => $provider,
            'label' => SerpProviderKeys::label($provider),
            'status' => $providerStatus['label'] ?? __('seo-content-ai::filament.api_connections.not_configured'),
            'status_code' => $providerStatus['status'] ?? 'not_configured',
            'configured' => ($providerStatus['configured'] ?? false) === true,
            'active' => ($providerStatus['active'] ?? false) === true,
            'last_checked_at' => $providerStatus['last_checked_at'] ?? null,
            'last_rank_check_at' => $providerStatus['last_rank_check_at'] ?? null,
            'usage_label' => $providerStatus['usage_label'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGscKpiCards(array $gscKpis, ?int $totalQueries): array
    {
        $hasData = ($gscKpis['has_data'] ?? false) === true;

        return [
            'total_clicks' => [
                'value' => $hasData ? (int) ($gscKpis['total_clicks'] ?? 0) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'total_impressions' => [
                'value' => $hasData ? (int) ($gscKpis['total_impressions'] ?? 0) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'avg_ctr' => [
                'value' => $hasData ? (float) ($gscKpis['avg_ctr'] ?? 0) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'avg_position' => [
                'value' => $hasData ? ($gscKpis['avg_position'] ?? null) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'total_queries' => [
                'value' => $hasData ? $totalQueries : null,
                'label' => $hasData ? null : 'not_synced',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rankingRows
     * @param  array<string, int>  $distribution
     * @return array<string, mixed>
     */
    private function buildRankKpiCards(array $rankingRows, array $distribution, bool $includeSearchVolume = false): array
    {
        $hasRankData = $rankingRows !== [];

        $avgPosition = null;
        if ($hasRankData) {
            $positions = collect($rankingRows)
                ->pluck('position')
                ->filter(static fn (mixed $value): bool => is_numeric($value))
                ->map(static fn (mixed $value): float => (float) $value);
            $avgPosition = $positions->isNotEmpty() ? round($positions->avg(), 1) : null;
        }

        $searchVolume = null;
        if ($includeSearchVolume && $hasRankData) {
            $volumeSum = collect($rankingRows)->sum(static fn (array $row): int => (int) ($row['volume'] ?? 0));
            $searchVolume = $volumeSum > 0 ? $volumeSum : null;
        }

        $kpis = [
            'tracked_keywords' => [
                'value' => $hasRankData ? count($rankingRows) : null,
                'label' => $hasRankData ? null : 'no_data',
            ],
            'top_3' => [
                'value' => $hasRankData ? (int) ($distribution['top_3'] ?? 0) : null,
                'label' => $hasRankData ? null : 'no_data',
            ],
            'top_10' => [
                'value' => $hasRankData ? (int) (($distribution['top_3'] ?? 0) + ($distribution['top_4_10'] ?? 0)) : null,
                'label' => $hasRankData ? null : 'no_data',
            ],
            'avg_position' => [
                'value' => $avgPosition,
                'label' => $avgPosition === null ? 'no_data' : null,
            ],
            'visibility' => [
                'value' => $hasRankData ? $this->calculateVisibilityScore($rankingRows) : null,
                'label' => $hasRankData ? null : 'not_synced',
            ],
        ];

        if ($includeSearchVolume) {
            $kpis['search_volume'] = [
                'value' => $searchVolume,
                'label' => $searchVolume === null ? 'no_data' : null,
            ];
        }

        return $kpis;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRankingRows(
        ?int $siteId,
        string $provider,
        string $keywordSearch,
        string $device,
        string $location,
        string $positionBucket = '',
    ): array {
    {
        if ($siteId === null || $siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return [];
        }

        $latestIds = KeywordRankSnapshot::query()
            ->selectRaw('MAX(id) as id')
            ->where('site_id', $siteId)
            ->where('provider', $provider)
            ->groupBy('keyword_id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($latestIds === []) {
            return [];
        }

        $query = KeywordRankSnapshot::query()
            ->whereIn('id', $latestIds)
            ->with('keyword')
            ->when($device !== 'all', static fn ($builder) => $builder->where('device', $device))
            ->when($location !== '', static fn ($builder) => $builder->where('location', $location));

        if (trim($keywordSearch) !== '') {
            $needle = '%'.addcslashes(trim($keywordSearch), '%_').'%';
            $query->whereHas('keyword', static fn ($builder) => $builder->where('phrase', 'like', $needle));
        }

        return $query
            ->orderBy('position')
            ->limit(200)
            ->get()
            ->map(function (KeywordRankSnapshot $snapshot): array {
                $previous = KeywordRankSnapshot::query()
                    ->where('site_id', $snapshot->site_id)
                    ->where('keyword_id', $snapshot->keyword_id)
                    ->where('provider', $provider)
                    ->where('id', '<', $snapshot->id)
                    ->orderByDesc('id')
                    ->first();

                $change = null;
                if ($previous !== null && $snapshot->position !== null && $previous->position !== null) {
                    $change = (int) round((float) $previous->position - (float) $snapshot->position);
                }

                return [
                    'keyword_id' => (int) $snapshot->keyword_id,
                    'keyword' => (string) ($snapshot->keyword?->phrase ?? ''),
                    'position' => $snapshot->position,
                    'change' => $change,
                    'volume' => $snapshot->search_volume,
                    'allintitle' => $snapshot->allintitle,
                    'url' => $snapshot->ranking_url,
                    'status' => $snapshot->request_status,
                    'error' => $snapshot->error_message,
                    'updated_at' => $snapshot->checked_at?->toDateTimeString(),
                ];
            })
            ->filter(function (array $row) use ($positionBucket): bool {
                if ($positionBucket === '') {
                    return true;
                }

                return $this->matchesPositionBucket($row['position'] ?? null, $positionBucket);
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function buildRankingDistribution(array $rows): array
    {
        $distribution = [
            'top_3' => 0,
            'top_4_10' => 0,
            'top_11_20' => 0,
            'top_21_50' => 0,
            'top_51_100' => 0,
        ];

        foreach ($rows as $row) {
            $position = $row['position'] ?? null;
            if (! is_numeric($position)) {
                continue;
            }

            $pos = (int) round((float) $position);
            if ($pos <= 3) {
                $distribution['top_3']++;
            } elseif ($pos <= 10) {
                $distribution['top_4_10']++;
            } elseif ($pos <= 20) {
                $distribution['top_11_20']++;
            } elseif ($pos <= 50) {
                $distribution['top_21_50']++;
            } elseif ($pos <= 100) {
                $distribution['top_51_100']++;
            }
        }

        return $distribution;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSerpChanges(?int $siteId, string $provider): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        return KeywordRankSnapshot::query()
            ->where('site_id', $siteId)
            ->where('provider', $provider)
            ->whereNotNull('position')
            ->orderByDesc('checked_at')
            ->limit(100)
            ->with('keyword')
            ->get()
            ->groupBy('keyword_id')
            ->map(function (Collection $group): ?array {
                if ($group->count() < 2) {
                    return null;
                }

                $latest = $group->first();
                $previous = $group->skip(1)->first();
                if ($latest === null || $previous === null) {
                    return null;
                }

                $change = (int) round((float) $previous->position - (float) $latest->position);
                if ($change === 0) {
                    return null;
                }

                return [
                    'keyword' => (string) ($latest->keyword?->phrase ?? ''),
                    'position' => $latest->position,
                    'change' => $change,
                    'volume' => $latest->search_volume,
                    'allintitle' => $latest->allintitle,
                    'url' => $latest->ranking_url,
                    'updated_at' => $latest->checked_at?->toDateTimeString(),
                ];
            })
            ->filter()
            ->take(50)
            ->values()
            ->all();
    }

    /**
     * @return array{labels: list<string>, current: list<int>, previous: list<int>, has_data: bool}
     */
    private function buildVisibilityChart(?int $siteId, string $provider): array
    {
        if ($siteId === null || $siteId <= 0) {
            return ['labels' => [], 'current' => [], 'previous' => [], 'has_data' => false];
        }

        $snapshots = KeywordRankSnapshot::query()
            ->where('site_id', $siteId)
            ->where('provider', $provider)
            ->where('checked_at', '>=', now()->subDays(56))
            ->orderBy('checked_at')
            ->get(['checked_at', 'position']);

        if ($snapshots->isEmpty()) {
            return ['labels' => [], 'current' => [], 'previous' => [], 'has_data' => false];
        }

        $currentBuckets = [];
        $previousBuckets = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot->checked_at === null || $snapshot->position === null) {
                continue;
            }

            $day = $snapshot->checked_at->toDateString();
            $visibility = max(0, 100 - (int) round((float) $snapshot->position));

            if ($snapshot->checked_at->gte(now()->subDays(28))) {
                $currentBuckets[$day] = ($currentBuckets[$day] ?? 0) + $visibility;
            } else {
                $previousBuckets[$day] = ($previousBuckets[$day] ?? 0) + $visibility;
            }
        }

        $labels = collect(array_keys($currentBuckets))->sort()->values()->all();

        return [
            'labels' => $labels,
            'current' => array_map(static fn (string $label): int => (int) ($currentBuckets[$label] ?? 0), $labels),
            'previous' => array_map(static fn (string $label): int => (int) ($previousBuckets[$label] ?? 0), $labels),
            'has_data' => $labels !== [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function calculateVisibilityScore(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $score = collect($rows)
            ->filter(static fn (array $row): bool => is_numeric($row['position'] ?? null))
            ->avg(static fn (array $row): int => max(0, 100 - (int) round((float) $row['position'])));

        return (int) round((float) $score);
    }

    private function matchesPositionBucket(mixed $position, string $bucket): bool
    {
        if (! is_numeric($position)) {
            return false;
        }

        $pos = (int) round((float) $position);

        return match ($bucket) {
            '1-3' => $pos <= 3,
            '4-10' => $pos >= 4 && $pos <= 10,
            '11-20' => $pos >= 11 && $pos <= 20,
            '21-50' => $pos >= 21 && $pos <= 50,
            '51-100' => $pos >= 51 && $pos <= 100,
            default => true,
        };
    }

    private function resolveSettingsUrl(): string
    {
        if (SeoConnectionContext::hash() === null) {
            return '#';
        }

        return AiConnectionResource::getUrl();
    }
}
