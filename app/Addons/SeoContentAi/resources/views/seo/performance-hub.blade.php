@php
    $cssPath = base_path('app/Addons/SeoContentAi/resources/css/performance-hub.css');
    $selectCssPath = base_path('app/Addons/SeoContentAi/resources/css/seo-select.css');
    $discoveryCssPath = base_path('app/Addons/SeoContentAi/resources/css/ai-keyword-discovery.css');
    $kpis = $this->gscKpis;
    $queries = $this->gscQueries;
    $quickWins = $this->quickWinRows;
    $cannibalization = $this->cannibalizationRows;
    $suggestions = $this->suggestions;
    $selectedCount = $this->getSelectedSuggestionCount();
    $activeTab = $this->activeTab;
@endphp

<x-filament-panels::page class="performance-hub-page">
    @if (is_readable($selectCssPath))
        <style>{!! file_get_contents($selectCssPath) !!}</style>
    @endif
    @if (is_readable($discoveryCssPath))
        <style>{!! file_get_contents($discoveryCssPath) !!}</style>
    @endif
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    <div class="performance-hub-shell space-y-5">
        <header class="performance-hub-header">
            <div>
                <h1 class="performance-hub-title">{{ __('seo-content-ai::filament.performance_hub.title') }}</h1>
                <p class="performance-hub-subtitle">{{ __('seo-content-ai::filament.performance_hub.subtitle') }}</p>
            </div>
        </header>

        <nav class="performance-hub-tabs" aria-label="{{ __('seo-content-ai::filament.performance_hub.tabs_label') }}">
            @foreach ([
                'gsc' => __('seo-content-ai::filament.performance_hub.tab_gsc'),
                'quick-wins' => __('seo-content-ai::filament.performance_hub.tab_quick_wins'),
                'ai-discovery' => __('seo-content-ai::filament.performance_hub.tab_ai_discovery'),
                'cannibalization' => __('seo-content-ai::filament.performance_hub.tab_cannibalization'),
            ] as $tabKey => $tabLabel)
                <button
                    type="button"
                    wire:click="setActiveTab('{{ $tabKey }}')"
                    wire:loading.attr="disabled"
                    wire:target="setActiveTab"
                    @class([
                        'performance-hub-tab',
                        'is-active' => $activeTab === $tabKey,
                    ])
                    aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                >
                    {{ $tabLabel }}
                </button>
            @endforeach
        </nav>

        @if ($activeTab === 'gsc')
            <section class="performance-hub-panel" wire:key="performance-tab-gsc">
                <div class="performance-hub-kpi-grid">
                    <article class="performance-hub-kpi-card">
                        <p class="performance-hub-kpi-label">{{ __('seo-content-ai::filament.performance_hub.kpi_clicks') }}</p>
                        <p class="performance-hub-kpi-value">{{ number_format((int) ($kpis['total_clicks'] ?? 0)) }}</p>
                    </article>
                    <article class="performance-hub-kpi-card">
                        <p class="performance-hub-kpi-label">{{ __('seo-content-ai::filament.performance_hub.kpi_impressions') }}</p>
                        <p class="performance-hub-kpi-value">{{ number_format((int) ($kpis['total_impressions'] ?? 0)) }}</p>
                    </article>
                    <article class="performance-hub-kpi-card">
                        <p class="performance-hub-kpi-label">{{ __('seo-content-ai::filament.performance_hub.kpi_ctr') }}</p>
                        <p class="performance-hub-kpi-value">{{ number_format((float) ($kpis['avg_ctr'] ?? 0), 2) }}%</p>
                    </article>
                    <article class="performance-hub-kpi-card">
                        <p class="performance-hub-kpi-label">{{ __('seo-content-ai::filament.performance_hub.kpi_position') }}</p>
                        <p class="performance-hub-kpi-value">
                            {{ ($kpis['avg_position'] ?? null) !== null ? number_format((float) $kpis['avg_position'], 1) : '—' }}
                        </p>
                    </article>
                </div>

                @if (! ($kpis['has_data'] ?? false))
                    <div class="performance-hub-empty">
                        {{ __('seo-content-ai::filament.performance_hub.gsc_empty') }}
                    </div>
                @endif

                <div class="performance-hub-table-wrap">
                    <table class="performance-hub-table">
                        <thead>
                            <tr>
                                @foreach ([
                                    'query' => __('seo-content-ai::filament.performance_hub.col_query'),
                                    'clicks' => __('seo-content-ai::filament.performance_hub.col_clicks'),
                                    'impressions' => __('seo-content-ai::filament.performance_hub.col_impressions'),
                                    'ctr' => __('seo-content-ai::filament.performance_hub.col_ctr'),
                                    'position' => __('seo-content-ai::filament.performance_hub.col_position'),
                                ] as $column => $label)
                                    <th scope="col">
                                        <button
                                            type="button"
                                            wire:click="sortGscQueries('{{ $column }}')"
                                            class="performance-hub-sort-btn"
                                        >
                                            {{ $label }}
                                            @if ($this->querySortBy === $column)
                                                <span aria-hidden="true">{{ $this->querySortDir === 'asc' ? '↑' : '↓' }}</span>
                                            @endif
                                        </button>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($queries as $row)
                                <tr wire:key="gsc-query-{{ md5((string) ($row['query'] ?? '')) }}">
                                    <td>{{ $row['query'] ?? '—' }}</td>
                                    <td>{{ number_format((int) ($row['clicks'] ?? 0)) }}</td>
                                    <td>{{ number_format((int) ($row['impressions'] ?? 0)) }}</td>
                                    <td>{{ number_format((float) ($row['ctr'] ?? 0), 2) }}%</td>
                                    <td>{{ ($row['position'] ?? null) !== null ? number_format((float) $row['position'], 1) : '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="performance-hub-table-empty">
                                        {{ __('seo-content-ai::filament.performance_hub.queries_empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($activeTab === 'quick-wins')
            <section class="performance-hub-panel" wire:key="performance-tab-quick-wins">
                <p class="performance-hub-panel-hint">{{ __('seo-content-ai::filament.performance_hub.quick_wins_hint') }}</p>
                <div class="performance-hub-table-wrap">
                    <table class="performance-hub-table">
                        <thead>
                            <tr>
                                <th>{{ __('seo-content-ai::filament.performance_hub.col_query') }}</th>
                                <th>{{ __('seo-content-ai::filament.performance_hub.col_position') }}</th>
                                <th>{{ __('seo-content-ai::filament.performance_hub.col_impressions') }}</th>
                                <th>{{ __('seo-content-ai::filament.performance_hub.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($quickWins as $row)
                                <tr wire:key="quick-win-{{ md5((string) ($row['query'] ?? '')) }}">
                                    <td class="font-medium text-gray-900 dark:text-white">{{ $row['query'] ?? '—' }}</td>
                                    <td>{{ number_format((float) ($row['position'] ?? 0), 1) }}</td>
                                    <td>{{ number_format((int) ($row['impressions'] ?? 0)) }}</td>
                                    <td>
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                wire:click="pushQuickWinToEditor({{ json_encode($row['query'] ?? '') }}, 'suggest')"
                                                wire:loading.attr="disabled"
                                                wire:target="pushQuickWinToEditor"
                                                class="performance-hub-action-btn"
                                            >
                                                <span wire:loading.remove wire:target="pushQuickWinToEditor">{{ __('seo-content-ai::filament.performance_hub.push_suggest') }}</span>
                                                <span wire:loading wire:target="pushQuickWinToEditor">…</span>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="pushQuickWinToEditor({{ json_encode($row['query'] ?? '') }}, 'normal')"
                                                wire:loading.attr="disabled"
                                                wire:target="pushQuickWinToEditor"
                                                class="performance-hub-action-btn performance-hub-action-btn--secondary"
                                            >
                                                {{ __('seo-content-ai::filament.performance_hub.push_focus') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="performance-hub-table-empty">
                                        {{ __('seo-content-ai::filament.performance_hub.quick_wins_empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($activeTab === 'ai-discovery')
            <section
                class="performance-hub-panel ai-discovery-layout mt-0"
                wire:key="performance-tab-ai-discovery"
                x-data="{
                    copyPhrase(phrase) {
                        if (! phrase) return;
                        navigator.clipboard?.writeText(phrase);
                    }
                }"
                @discovery-copy-keyword.window="copyPhrase($event.detail.phrase)"
            >
                <aside class="ai-discovery-form-pane">
                    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/40">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ __('seo-content-ai::filament.keyword.discovery_form_heading') }}
                            </h2>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.keyword.discovery_form_hint') }}
                            </p>
                        </div>

                        <form wire:submit="generateAiKeywords" class="space-y-4 p-4">
                            <div>
                                <label for="discovery-seed" class="ai-discovery-label">
                                    {{ __('seo-content-ai::filament.keyword.discovery_seed_label') }}
                                </label>
                                <input
                                    id="discovery-seed"
                                    type="text"
                                    wire:model="seedKeyword"
                                    wire:loading.attr="disabled"
                                    wire:target="generateAiKeywords"
                                    placeholder="{{ __('seo-content-ai::filament.keyword.discovery_seed_placeholder') }}"
                                    class="ai-discovery-input"
                                />
                            </div>

                            <div>
                                <label for="discovery-intent" class="ai-discovery-label">
                                    {{ __('seo-content-ai::filament.keyword.discovery_intent_label') }}
                                </label>
                                <x-seo-content-ai::seo-select
                                    id="discovery-intent"
                                    wire:model="searchIntent"
                                    wire:loading.attr="disabled"
                                    wire:target="generateAiKeywords"
                                    class="ai-discovery-select"
                                >
                                    <option value="any">{{ __('seo-content-ai::filament.keyword.discovery_intent_any') }}</option>
                                    <option value="informational">{{ __('seo-content-ai::filament.keyword.discovery_intent_informational') }}</option>
                                    <option value="commercial">{{ __('seo-content-ai::filament.keyword.discovery_intent_commercial') }}</option>
                                    <option value="transactional">{{ __('seo-content-ai::filament.keyword.discovery_intent_transactional') }}</option>
                                </x-seo-content-ai::seo-select>
                            </div>

                            <div>
                                <label for="discovery-region" class="ai-discovery-label">
                                    {{ __('seo-content-ai::filament.keyword.discovery_region_label') }}
                                </label>
                                <x-seo-content-ai::seo-select
                                    id="discovery-region"
                                    wire:model="targetRegion"
                                    wire:loading.attr="disabled"
                                    wire:target="generateAiKeywords"
                                    class="ai-discovery-select"
                                >
                                    <option value="vietnam">{{ __('seo-content-ai::filament.keyword.discovery_region_vietnam') }}</option>
                                    <option value="global">{{ __('seo-content-ai::filament.keyword.discovery_region_global') }}</option>
                                    <option value="us">{{ __('seo-content-ai::filament.keyword.discovery_region_us') }}</option>
                                    <option value="uk">{{ __('seo-content-ai::filament.keyword.discovery_region_uk') }}</option>
                                    <option value="sea">{{ __('seo-content-ai::filament.keyword.discovery_region_sea') }}</option>
                                </x-seo-content-ai::seo-select>
                            </div>

                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="generateAiKeywords"
                                class="ai-discovery-generate-btn"
                            >
                                <span wire:loading.remove wire:target="generateAiKeywords" class="inline-flex items-center gap-2">
                                    <span aria-hidden="true">✨</span>
                                    {{ __('seo-content-ai::filament.keyword.discovery_generate') }}
                                </span>
                                <span wire:loading wire:target="generateAiKeywords" class="inline-flex items-center gap-2">
                                    <svg class="ai-discovery-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ __('seo-content-ai::filament.keyword.discovery_generating') }}
                                </span>
                            </button>
                        </form>
                    </div>
                </aside>

                <section class="ai-discovery-results-pane min-w-0">
                    @if ($suggestions === [])
                        @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-empty-state')
                    @else
                        @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-results-table', [
                            'suggestions' => $suggestions,
                            'selectedSuggestionIds' => $this->selectedSuggestionIds,
                            'isAllSelected' => $this->isAllSelected(),
                        ])
                    @endif
                </section>

                @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-action-bar', [
                    'selectedCount' => $selectedCount,
                ])
            </section>
        @endif

        @if ($activeTab === 'cannibalization')
            <section class="performance-hub-panel" wire:key="performance-tab-cannibalization">
                <p class="performance-hub-panel-hint">{{ __('seo-content-ai::filament.performance_hub.cannibalization_hint') }}</p>
                <div class="performance-hub-table-wrap">
                    <table class="performance-hub-table">
                        <thead>
                            <tr>
                                <th>{{ __('seo-content-ai::filament.performance_hub.col_focus_keyword') }}</th>
                                <th>{{ __('seo-content-ai::filament.performance_hub.col_article_count') }}</th>
                                <th>{{ __('seo-content-ai::filament.performance_hub.col_articles') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cannibalization as $row)
                                <tr wire:key="cannibal-{{ md5((string) ($row['phrase'] ?? '')) }}" class="performance-hub-warning-row">
                                    <td class="font-semibold text-amber-700 dark:text-amber-300">{{ $row['phrase'] ?? '—' }}</td>
                                    <td>{{ (int) ($row['article_count'] ?? 0) }}</td>
                                    <td>
                                        <ul class="space-y-1">
                                            @foreach (($row['articles'] ?? []) as $article)
                                                <li>
                                                    <a href="{{ $article['url'] ?? '#' }}" class="performance-hub-link">
                                                        {{ $article['title'] ?? '—' }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="performance-hub-table-empty">
                                        {{ __('seo-content-ai::filament.performance_hub.cannibalization_empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
