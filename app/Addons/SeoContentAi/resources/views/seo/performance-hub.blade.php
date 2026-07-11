@php
    use App\Addons\SeoContentAi\Support\SerpProviderKeys;

    $cssPath = base_path('app/Addons/SeoContentAi/resources/css/performance-hub.css');
    $dataSource = $this->dataSource;
    $activeTab = $this->activeTab;
    $isRankProvider = SerpProviderKeys::isValid($dataSource);
@endphp

<x-filament-panels::page class="performance-hub-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    <div class="performance-hub-shell space-y-6">
        @include('seo-content-ai::seo.performance-hub.partials.header', ['dataSource' => $dataSource])
        @include('seo-content-ai::seo.performance-hub.partials.source-tabs', ['dataSource' => $dataSource])

        @if ($dataSource === 'gsc')
            @php $gscState = $this->gscDashboardState; @endphp
            <div class="performance-hub-gsc-stack">
            @include('seo-content-ai::seo.performance-hub.partials.gsc-connection-strip', [
                'connection' => $gscState['connection'] ?? [],
                'settingsUrl' => $gscState['settings_url'] ?? '#',
            ])
            @include('seo-content-ai::seo.performance-hub.partials.gsc-kpi-cards', ['kpis' => $gscState['kpis'] ?? []])
            @include('seo-content-ai::seo.performance-hub.partials.gsc-chart', ['chart' => $gscState['chart'] ?? []])
            @include('seo-content-ai::seo.performance-hub.partials.gsc-distribution', [
                'distribution' => $gscState['distribution'] ?? [],
                'activeBucket' => $gscState['position_bucket'] ?? '',
            ])
            </div>

            @if ($this->gscBulkSyncResult)
                @include('seo-content-ai::seo.performance-hub.partials.gsc-bulk-sync-summary', [
                    'result' => $this->gscBulkSyncResult,
                ])
            @endif

            <nav class="performance-hub-tabs" aria-label="{{ __('seo-content-ai::filament.performance_hub.tabs_label') }}">
                @foreach ([
                    'queries' => __('seo-content-ai::filament.performance_hub.tab_queries'),
                    'quick-wins' => __('seo-content-ai::filament.performance_hub.tab_quick_wins'),
                ] as $tabKey => $tabLabel)
                    <button
                        type="button"
                        wire:click="setActiveTab('{{ $tabKey }}')"
                        wire:loading.attr="disabled"
                        wire:target="setActiveTab"
                        @class(['performance-hub-tab', 'is-active' => $activeTab === $tabKey])
                        aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                    >
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </nav>

            @if ($activeTab === 'queries')
                @include('seo-content-ai::seo.performance-hub.partials.gsc-queries-table', [
                    'queries' => $gscState['queries'] ?? [],
                    'hasData' => ($gscState['has_data'] ?? false) === true,
                    'pagination' => $gscState['queries_pagination'] ?? [],
                    'totalFiltered' => $gscState['queries_total_filtered'] ?? 0,
                    'totalSource' => $gscState['queries_total_source'] ?? 0,
                    'activeBucket' => $gscState['position_bucket'] ?? '',
                ])
            @endif

            @if ($activeTab === 'quick-wins')
                @include('seo-content-ai::seo.performance-hub.partials.quick-wins-table', ['rows' => $gscState['quick_wins'] ?? []])
            @endif
        @endif

        @if ($isRankProvider)
            @php $rankState = $this->rankDashboardState; @endphp
            @include('seo-content-ai::seo.performance-hub.partials.rank-connection-strip', [
                'connections' => $rankState['connections'] ?? [],
            ])
            @include('seo-content-ai::seo.performance-hub.partials.rank-kpi-cards', ['kpis' => $rankState['kpis'] ?? []])
            @include('seo-content-ai::seo.performance-hub.partials.visibility-chart', ['chart' => $rankState['visibility_chart'] ?? []])
            @include('seo-content-ai::seo.performance-hub.partials.ranking-distribution', [
                'distribution' => $rankState['distribution'] ?? [],
                'activeBucket' => $rankState['position_bucket'] ?? '',
            ])

            @if (\App\Addons\SeoContentAi\Support\SeoAccessControl::canAccessManagerFeatures())
                @include('seo-content-ai::seo.performance-hub.partials.provider-comparison', [
                    'rows' => $this->comparisonRows,
                ])
            @endif

            <nav class="performance-hub-tabs" aria-label="{{ __('seo-content-ai::filament.performance_hub.tabs_label') }}">
                @foreach ([
                    'rankings' => __('seo-content-ai::filament.performance_hub.tab_rankings'),
                    'serp-changes' => __('seo-content-ai::filament.performance_hub.tab_serp_changes'),
                ] as $tabKey => $tabLabel)
                    <button
                        type="button"
                        wire:click="setActiveTab('{{ $tabKey }}')"
                        wire:loading.attr="disabled"
                        wire:target="setActiveTab"
                        @class(['performance-hub-tab', 'is-active' => $activeTab === $tabKey])
                        aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                    >
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </nav>

            @if ($activeTab === 'rankings')
                @include('seo-content-ai::seo.performance-hub.partials.rankings-table', ['rows' => $rankState['ranking_rows'] ?? []])
            @endif

            @if ($activeTab === 'serp-changes')
                @include('seo-content-ai::seo.performance-hub.partials.serp-changes-table', ['rows' => $rankState['serp_changes'] ?? []])
            @endif
        @endif
    </div>
</x-filament-panels::page>
