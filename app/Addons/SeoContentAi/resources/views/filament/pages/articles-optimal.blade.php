@php
    $paginator = $this->resultsPaginator;
    $reviewedGroups = $this->reviewedArticlesGrouped;
    $projectOptions = $this->getContentProjectOptions();
    $projectSiteOptions = $this->getSidebarProjectSiteOptions();
    $writerOptions = $this->getWriterOptions();
    $assignTypeOptions = $this->getAssignTypeOptions();
    $rewriteModeOptions = $this->getRewriteModeOptions();
    $sidebarArticles = $this->getSidebarProjectArticles();
    $visibleIds = collect($paginator->items())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    $defaultExpandedDate = $reviewedGroups[0]['date'] ?? null;

    $reviewedToday = now()->toDateString();
    $reviewedUiContext = [
        'today' => $reviewedToday,
        'weekStart' => now()->startOfWeek()->toDateString(),
        'weekEnd' => now()->endOfWeek()->toDateString(),
        'monthStart' => now()->startOfMonth()->toDateString(),
        'monthEnd' => now()->endOfMonth()->toDateString(),
    ];

    $reviewedGroupsEnriched = [];
    foreach ($reviewedGroups as $group) {
        $articles = $group['articles'];
        $articleCount = count($articles);

        $reviewedGroupsEnriched[] = array_merge($group, [
            'first_review' => $articleCount > 0 ? (string) ($articles[$articleCount - 1]['reviewed_time'] ?? '—') : '—',
            'last_review' => $articleCount > 0 ? (string) ($articles[0]['reviewed_time'] ?? '—') : '—',
            'is_today' => ($group['date'] ?? '') === $reviewedToday,
        ]);
    }
@endphp

@php
    $articlesOptimalCss = <<<'CSS'
.articles-optimal-tabs-bar {
    display: flex;
    gap: 0.35rem;
    padding: 0.35rem;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
}
.dark .articles-optimal-tabs-bar {
    background: rgb(17 24 39);
    border-color: rgb(71 85 105);
}
.articles-optimal-tab {
    padding: 0.45rem 0.9rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #646970;
    border: none;
    border-radius: 6px;
    background: transparent;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}
.articles-optimal-tab.is-active {
    color: #2271b1;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}
.dark .articles-optimal-tab.is-active {
    color: #60a5fa;
    background: rgb(51 65 85);
}
.reviewed-dashboard {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.reviewed-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}
@media (max-width: 1024px) {
    .reviewed-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 640px) {
    .reviewed-stats-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
.reviewed-stat-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    transition: background 0.15s, box-shadow 0.15s;
}
.reviewed-stat-card:hover {
    background: #F9FAFB;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}
.dark .reviewed-stat-card {
    background: rgb(17 24 39);
    border-color: rgb(55 65 81);
}
.dark .reviewed-stat-card:hover {
    background: rgb(31 41 55);
}
.reviewed-stat-card__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: 10px;
    background: #EFF6FF;
    color: #2563EB;
}
.dark .reviewed-stat-card__icon {
    background: rgb(30 58 95);
    color: #93C5FD;
}
.reviewed-stat-card__title {
    font-size: 0.8125rem;
    font-weight: 500;
    color: #6B7280;
}
.dark .reviewed-stat-card__title {
    color: #9CA3AF;
}
.reviewed-stat-card__value {
    margin-top: 2px;
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.2;
    color: #111827;
    letter-spacing: -0.02em;
}
.dark .reviewed-stat-card__value {
    color: #F9FAFB;
}
.reviewed-stat-card__subtitle {
    margin-top: 2px;
    font-size: 0.75rem;
    color: #9CA3AF;
}
.reviewed-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px 16px;
}
.reviewed-toolbar__search {
    flex: 1 1 220px;
    min-width: 0;
}
.reviewed-toolbar__filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
}
.reviewed-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 140px;
}
.reviewed-field__label {
    font-size: 0.75rem;
    font-weight: 500;
    color: #6B7280;
}
.dark .reviewed-field__label {
    color: #9CA3AF;
}
.reviewed-field__input {
    display: block;
    width: 100%;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    line-height: 1.25rem;
    color: #111827;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    transition: border-color 0.15s, box-shadow 0.15s;
}
.reviewed-field__input:focus {
    outline: none;
    border-color: #93C5FD;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}
.dark .reviewed-field__input {
    color: #F9FAFB;
    background: rgb(17 24 39);
    border-color: rgb(55 65 81);
}
.reviewed-day-groups {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.reviewed-day-card {
    overflow: hidden;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    transition: box-shadow 0.15s;
}
.reviewed-day-card:hover {
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}
.dark .reviewed-day-card {
    background: rgb(17 24 39);
    border-color: rgb(55 65 81);
}
.reviewed-day-card__trigger {
    display: flex;
    width: 100%;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px;
    text-align: left;
    background: #fff;
    border: none;
    cursor: pointer;
    transition: background 0.15s;
}
.reviewed-day-card__trigger:hover {
    background: #F9FAFB;
}
.dark .reviewed-day-card__trigger {
    background: rgb(17 24 39);
}
.dark .reviewed-day-card__trigger:hover {
    background: rgb(31 41 55);
}
.reviewed-day-card__title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.reviewed-day-card__date {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #111827;
}
.dark .reviewed-day-card__date {
    color: #F9FAFB;
}
.reviewed-day-card__today {
    font-size: 0.8125rem;
    font-weight: 500;
    color: #2563EB;
}
.dark .reviewed-day-card__today {
    color: #93C5FD;
}
.reviewed-day-card__badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #1D4ED8;
    background: #DBEAFE;
    border-radius: 9999px;
}
.dark .reviewed-day-card__badge {
    color: #BFDBFE;
    background: rgb(30 58 95);
}
.reviewed-day-card__meta {
    display: none;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
    font-size: 0.75rem;
    color: #6B7280;
}
@media (min-width: 768px) {
    .reviewed-day-card__meta {
        display: flex;
    }
}
.reviewed-day-card__meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.reviewed-day-card__meta-label {
    font-size: 0.6875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #9CA3AF;
}
.reviewed-day-card__meta-value {
    font-size: 0.8125rem;
    font-weight: 600;
    color: #374151;
}
.dark .reviewed-day-card__meta-value {
    color: #E5E7EB;
}
.reviewed-day-card__chevron {
    width: 1.25rem;
    height: 1.25rem;
    flex-shrink: 0;
    color: #9CA3AF;
    transition: transform 0.2s ease;
}
.reviewed-day-card__chevron.is-open {
    transform: rotate(180deg);
}
.reviewed-day-card__body {
    border-top: 1px solid #E5E7EB;
}
.dark .reviewed-day-card__body {
    border-top-color: rgb(55 65 81);
}
.reviewed-article-list {
    display: flex;
    flex-direction: column;
}
.reviewed-article-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    border-bottom: 1px solid #F3F4F6;
    transition: background 0.15s;
}
.reviewed-article-item:last-child {
    border-bottom: none;
}
.reviewed-article-item:hover {
    background: #F9FAFB;
}
.dark .reviewed-article-item {
    border-bottom-color: rgb(31 41 55);
}
.dark .reviewed-article-item:hover {
    background: rgb(31 41 55);
}
.reviewed-article-item__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: 10px;
    background: #F3F4F6;
    color: #6B7280;
}
.dark .reviewed-article-item__icon {
    background: rgb(31 41 55);
    color: #9CA3AF;
}
.reviewed-article-item__content {
    flex: 1 1 auto;
    min-width: 0;
}
.reviewed-article-item__title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #111827;
    line-height: 1.4;
    word-break: break-word;
}
.dark .reviewed-article-item__title {
    color: #F9FAFB;
}
.reviewed-article-item__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    font-size: 0.75rem;
    color: #6B7280;
}
.reviewed-article-item__status-dot {
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    background: #22C55E;
}
.reviewed-article-item__actions {
    display: flex;
    flex-shrink: 0;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
@media (max-width: 640px) {
    .reviewed-article-item {
        flex-wrap: wrap;
    }
    .reviewed-article-item__actions {
        width: 100%;
        padding-left: 54px;
    }
}
CSS;
@endphp

<style>{!! $articlesOptimalCss !!}</style>

<div
    class="fi-page articles-optimal-page"
    x-data="{
        activeTab: 'audit',
        sidebarProjectId: @entangle('sidebarProjectId').live,
        selectedArticleIds: @entangle('selectedArticleIds').live,
        sidebarCollapsed: false,
        assignOpen: false,
        quickCreateOpen: false,
        assignArticleId: null,
        assignProjectId: '',
        assignType: 'rewrite',
        rewriteMode: 'keyword',
        rewriteNotes: '',
        assignSubmitting: false,
        quickSiteId: @js((int) ($filterSiteId ?: \App\Addons\SeoContentAi\Support\SeoAccessControl::globalSiteId() ?: 0)),
        quickWriterId: '',
        quickCreateSubmitting: false,
        visibleIds: @js($visibleIds),
        reviewedUiContext: @js($reviewedUiContext),
        reviewedGroups: @js($reviewedGroupsEnriched),
        reviewedSearch: '',
        reviewedDateFilter: 'all',
        reviewedStatus: 'reviewed',
        reviewedSort: 'newest',
        reviewedBadgeTemplate: @js(__('seo-content-ai::filament.articles_optimal.reviewed_badge_articles', ['count' => ':count'])),
        expandedDates: @js($defaultExpandedDate ? [$defaultExpandedDate] : []),
        reviewedBadgeLabel(count) {
            return this.reviewedBadgeTemplate.replace(':count', String(count));
        },
        countReviewedInRange(start, end) {
            return this.reviewedGroups.reduce((sum, group) => {
                if (group.date >= start && group.date <= end) {
                    return sum + group.count;
                }
                return sum;
            }, 0);
        },
        reviewedStatToday() {
            const { today } = this.reviewedUiContext;
            return this.countReviewedInRange(today, today);
        },
        reviewedStatWeek() {
            const { weekStart, weekEnd } = this.reviewedUiContext;
            return this.countReviewedInRange(weekStart, weekEnd);
        },
        reviewedStatMonth() {
            const { monthStart, monthEnd } = this.reviewedUiContext;
            return this.countReviewedInRange(monthStart, monthEnd);
        },
        reviewedStatTotal() {
            return this.reviewedGroups.reduce((sum, group) => sum + group.count, 0);
        },
        filteredReviewedGroups() {
            const ctx = this.reviewedUiContext;
            let groups = this.reviewedGroups.map((group) => ({
                ...group,
                articles: [...group.articles],
            }));

            if (this.reviewedDateFilter === 'today') {
                groups = groups.filter((group) => group.date === ctx.today);
            } else if (this.reviewedDateFilter === 'week') {
                groups = groups.filter((group) => group.date >= ctx.weekStart && group.date <= ctx.weekEnd);
            } else if (this.reviewedDateFilter === 'month') {
                groups = groups.filter((group) => group.date >= ctx.monthStart && group.date <= ctx.monthEnd);
            }

            const query = this.reviewedSearch.trim().toLowerCase();
            if (query !== '') {
                groups = groups
                    .map((group) => {
                        const articles = group.articles.filter((article) => (article.title || '').toLowerCase().includes(query));
                        return { ...group, articles, count: articles.length };
                    })
                    .filter((group) => group.count > 0);
            }

            groups.sort((left, right) => {
                if (this.reviewedSort === 'oldest') {
                    return left.date.localeCompare(right.date);
                }
                return right.date.localeCompare(left.date);
            });

            return groups;
        },
        toggleDate(dateKey) {
            if (this.expandedDates.includes(dateKey)) {
                this.expandedDates = this.expandedDates.filter((value) => value !== dateKey);
                return;
            }
            this.expandedDates = [...this.expandedDates, dateKey];
        },
        isDateExpanded(dateKey) {
            return this.expandedDates.includes(dateKey);
        },
        visibleSelected() {
            return this.visibleIds.length > 0 && this.visibleIds.every((id) => this.selectedArticleIds.map(Number).includes(Number(id)));
        },
        syncVisibleIds(nextVisibleIds) {
            this.visibleIds = nextVisibleIds.map(Number);
            this.selectedArticleIds = this.selectedArticleIds
                .map(Number)
                .filter((id) => this.visibleIds.includes(id));
        },
        toggleSelectAll(checked) {
            this.selectedArticleIds = checked ? this.visibleIds.map(Number) : [];
        },
        openAssign(articleId) {
            this.assignArticleId = articleId;
            this.assignProjectId = this.sidebarProjectId || '';
            this.assignType = 'rewrite';
            this.rewriteMode = 'keyword';
            this.rewriteNotes = '';
            this.assignOpen = true;
        },
        submitAssign() {
            this.assignSubmitting = true;
            this.$wire.assignArticleToContentProject(this.assignArticleId, {
                project_id: this.assignProjectId,
                type: this.assignType,
                rewrite_mode: this.rewriteMode,
                rewrite_notes: this.rewriteNotes,
            }).then(() => {
                this.assignOpen = false;
            }).finally(() => {
                this.assignSubmitting = false;
            });
        },
        submitQuickCreate() {
            this.quickCreateSubmitting = true;
            this.$wire.quickCreateSidebarProject({ site_id: this.quickSiteId, user_id: this.quickWriterId }).then(() => {
                this.quickCreateOpen = false;
            }).finally(() => {
                this.quickCreateSubmitting = false;
            });
        },
    }"
>
    <span
        wire:key="articles-optimal-visible-ids-{{ md5(json_encode($visibleIds)) }}"
        x-init="syncVisibleIds(@js($visibleIds))"
        class="hidden"
    ></span>

    <div
        class="space-y-6 transition-all duration-300"
        x-bind:style="activeTab === 'audit' && ! sidebarCollapsed ? 'padding-right: 31%;' : 'padding-right: 0;'"
    >
        <div class="articles-optimal-tabs-bar">
            <button
                type="button"
                class="articles-optimal-tab"
                x-bind:class="activeTab === 'audit' ? 'is-active' : ''"
                x-on:click="activeTab = 'audit'"
            >
                {{ __('seo-content-ai::filament.articles_optimal.tab_audit') }}
            </button>
            <button
                type="button"
                class="articles-optimal-tab"
                x-bind:class="activeTab === 'reviewed' ? 'is-active' : ''"
                x-on:click="activeTab = 'reviewed'"
            >
                {{ __('seo-content-ai::filament.articles_optimal.tab_reviewed') }}
            </button>
        </div>

        <div x-show="activeTab === 'audit'" x-cloak class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                {{ __('seo-content-ai::filament.articles_optimal.filters_heading') }}
            </x-slot>

            <form wire:submit="runScan" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.articles_optimal.domain_label') }}
                        </label>
                        <x-select
                            wire:model.live="filterSiteId"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            <option value="">{{ __('seo-content-ai::filament.articles_optimal.domain_all') }}</option>
                            @foreach ($this->getSiteFilterOptions() as $siteId => $domainLabel)
                                <option value="{{ $siteId }}">{{ $domainLabel }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.articles_optimal.language_label') }}
                        </label>
                        <x-select
                            wire:model.live="filterLanguage"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            <option value="">{{ __('seo-content-ai::filament.articles_optimal.language_all') }}</option>
                            @foreach ($this->getLanguageOptions() as $langCode => $langLabel)
                                <option value="{{ $langCode }}">{{ $langLabel }}</option>
                            @endforeach
                        </x-select>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="filterThinContent" class="rounded border-gray-300">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_thin_content') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="filterPoorImageDensity" class="rounded border-gray-300">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_poor_image') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="filterMissingH2" class="rounded border-gray-300">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_missing_h2') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="filterMissingFaq" class="rounded border-gray-300">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_missing_faq') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="filterLowSeoScore" class="rounded border-gray-300">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_low_score') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="filterTechnicalSeoScore" class="rounded border-gray-300">
                        {{ __('seo-content-ai::filament.articles_optimal.filter_technical_seo_score') }}
                    </label>
                </div>

                <div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="runScan">
                        <span wire:loading.remove wire:target="runScan">
                            {{ __('seo-content-ai::filament.articles_optimal.scan_button') }}
                        </span>
                        <span wire:loading wire:target="runScan">
                            {{ __('seo-content-ai::filament.articles_optimal.scanning') }}
                        </span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('seo-content-ai::filament.articles_optimal.results_heading') }}
            </x-slot>

            @if (! $hasScanned)
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.articles_optimal.initial_message') }}
                </p>
            @elseif ($paginator->total() === 0)
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.articles_optimal.empty_results') }}
                </p>
            @else
                <div class="mb-3 flex items-center gap-2">
                    <x-filament::button
                        type="button"
                        size="sm"
                        color="warning"
                        x-on:click="$wire.assignSelectedArticlesToSelectedProject(sidebarProjectId).then(() => window.location.reload())"
                        wire:loading.attr="disabled"
                        wire:target="assignSelectedArticlesToSelectedProject"
                        wire:loading.class="opacity-60 pointer-events-none"
                        x-bind:disabled="!sidebarProjectId || selectedArticleIds.length === 0"
                    >
                        <span wire:loading.remove wire:target="assignSelectedArticlesToSelectedProject">Assign selected</span>
                        <span wire:loading.inline-flex wire:target="assignSelectedArticlesToSelectedProject" class="items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            Assigning...
                        </span>
                    </x-filament::button>
                    <span class="text-xs text-gray-500">Chọn project ở sidebar để bulk assign nhanh.</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="w-10 px-3 py-2 text-left font-semibold">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300"
                                        x-bind:checked="visibleSelected()"
                                        x-on:change="toggleSelectAll($event.target.checked)"
                                    >
                                </th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_title') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_domain') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_warnings') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_score') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($paginator as $row)
                                <tr wire:key="article-optimal-{{ $row['id'] }}">
                                    <td class="px-3 py-3 align-top">
                                        <input
                                            type="checkbox"
                                            value="{{ $row['id'] }}"
                                            class="rounded border-gray-300"
                                            x-bind:checked="selectedArticleIds.map(Number).includes({{ (int) $row['id'] }})"
                                            x-on:change="
                                                const id = {{ (int) $row['id'] }};
                                                selectedArticleIds = $event.target.checked
                                                    ? Array.from(new Set([...selectedArticleIds.map(Number), id]))
                                                    : selectedArticleIds.map(Number).filter((value) => value !== id);
                                            "
                                        >
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        @if (! empty($row['permalink']))
                                            <a href="{{ $row['permalink'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $row['title'] }}
                                            </a>
                                        @else
                                            <span class="font-medium">{{ $row['title'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top text-gray-600 dark:text-gray-300">{{ $row['domain'] }}</td>
                                    <td class="px-3 py-3 align-top">
                                        <ul class="list-disc pl-4 space-y-1 text-gray-700 dark:text-gray-300">
                                            @foreach ($row['reason_labels'] as $label)
                                                <li>{{ $label }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <span @class([
                                            'font-semibold',
                                            'text-rose-600 dark:text-rose-400' => (int) ($row['score'] ?? 0) < 50,
                                            'text-amber-600 dark:text-amber-400' => (int) ($row['score'] ?? 0) >= 50 && (int) ($row['score'] ?? 0) <= 70,
                                            'text-emerald-600 dark:text-emerald-400' => (int) ($row['score'] ?? 0) > 70,
                                        ])>{{ (int) ($row['score'] ?? 0) }}</span>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <div class="flex flex-wrap gap-2">
                                            <x-filament::icon-button tag="a" href="{{ $row['edit_url'] }}" icon="heroicon-o-pencil-square" size="sm" color="gray" tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_edit') }}" />
                                            <x-filament::icon-button icon="heroicon-o-archive-box-arrow-down" size="sm" color="warning" wire:click="demoteToDraft({{ $row['id'] }})" wire:loading.attr="disabled" wire:loading.class="opacity-50 pointer-events-none" wire:target="demoteToDraft" tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_demote_draft') }}" />
                                            <x-filament::icon-button
                                                icon="heroicon-o-folder-plus"
                                                size="sm"
                                                color="info"
                                                x-on:click="sidebarProjectId ? $wire.assignArticleToSelectedProject({{ $row['id'] }}) : openAssign({{ $row['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:loading.class="opacity-50 pointer-events-none"
                                                wire:target="assignArticleToSelectedProject"
                                                tooltip="{{ __('seo-content-ai::filament.articles_optimal.action_assign_project') }}"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $paginator->links() }}
                </div>
            @endif
        </x-filament::section>
        </div>

        <div x-show="activeTab === 'reviewed'" x-cloak>
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('seo-content-ai::filament.articles_optimal.reviewed_heading') }}
                </x-slot>

                @if ($reviewedGroups === [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('seo-content-ai::filament.articles_optimal.reviewed_empty') }}
                    </p>
                @else
                    <div class="reviewed-dashboard">
                        <div class="reviewed-stats-grid">
                            <div class="reviewed-stat-card">
                                <div class="reviewed-stat-card__icon">
                                    <x-filament::icon icon="heroicon-o-sun" class="h-5 w-5" />
                                </div>
                                <div>
                                    <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_today') }}</div>
                                    <div class="reviewed-stat-card__value" x-text="reviewedStatToday()">0</div>
                                    <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                                </div>
                            </div>
                            <div class="reviewed-stat-card">
                                <div class="reviewed-stat-card__icon">
                                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                                </div>
                                <div>
                                    <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_week') }}</div>
                                    <div class="reviewed-stat-card__value" x-text="reviewedStatWeek()">0</div>
                                    <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                                </div>
                            </div>
                            <div class="reviewed-stat-card">
                                <div class="reviewed-stat-card__icon">
                                    <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5" />
                                </div>
                                <div>
                                    <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_month') }}</div>
                                    <div class="reviewed-stat-card__value" x-text="reviewedStatMonth()">0</div>
                                    <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                                </div>
                            </div>
                            <div class="reviewed-stat-card">
                                <div class="reviewed-stat-card__icon">
                                    <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
                                </div>
                                <div>
                                    <div class="reviewed-stat-card__title">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_total') }}</div>
                                    <div class="reviewed-stat-card__value" x-text="reviewedStatTotal()">0</div>
                                    <div class="reviewed-stat-card__subtitle">{{ __('seo-content-ai::filament.articles_optimal.reviewed_stat_unit') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="reviewed-toolbar">
                            <div class="reviewed-toolbar__search">
                                <label class="reviewed-field__label sr-only" for="reviewed-search-input">{{ __('seo-content-ai::filament.articles_optimal.reviewed_search_placeholder') }}</label>
                                <input
                                    id="reviewed-search-input"
                                    type="search"
                                    x-model="reviewedSearch"
                                    class="reviewed-field__input"
                                    placeholder="{{ __('seo-content-ai::filament.articles_optimal.reviewed_search_placeholder') }}"
                                    autocomplete="off"
                                >
                            </div>
                            <div class="reviewed-toolbar__filters">
                                <div class="reviewed-field">
                                    <label class="reviewed-field__label" for="reviewed-date-filter">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date') }}</label>
                                    <x-select id="reviewed-date-filter" x-model="reviewedDateFilter" class="reviewed-field__input">
                                        <option value="all">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_all') }}</option>
                                        <option value="today">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_today') }}</option>
                                        <option value="week">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_week') }}</option>
                                        <option value="month">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_date_month') }}</option>
                                    </x-select>
                                </div>
                                <div class="reviewed-field">
                                    <label class="reviewed-field__label" for="reviewed-status-filter">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_status') }}</label>
                                    <x-select id="reviewed-status-filter" x-model="reviewedStatus" class="reviewed-field__input">
                                        <option value="reviewed">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_status_reviewed') }}</option>
                                    </x-select>
                                </div>
                                <div class="reviewed-field">
                                    <label class="reviewed-field__label" for="reviewed-sort-filter">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort') }}</label>
                                    <x-select id="reviewed-sort-filter" x-model="reviewedSort" class="reviewed-field__input">
                                        <option value="newest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_newest') }}</option>
                                        <option value="oldest">{{ __('seo-content-ai::filament.articles_optimal.reviewed_filter_sort_oldest') }}</option>
                                    </x-select>
                                </div>
                            </div>
                        </div>

                        <p
                            x-show="filteredReviewedGroups().length === 0"
                            x-cloak
                            class="text-sm text-gray-600 dark:text-gray-300"
                        >
                            {{ __('seo-content-ai::filament.articles_optimal.reviewed_no_matches') }}
                        </p>

                        <div class="reviewed-day-groups" x-show="filteredReviewedGroups().length > 0">
                            <template x-for="group in filteredReviewedGroups()" :key="group.date">
                                <div class="reviewed-day-card">
                                    <button
                                        type="button"
                                        class="reviewed-day-card__trigger"
                                        x-on:click="toggleDate(group.date)"
                                        x-bind:aria-expanded="isDateExpanded(group.date)"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <div class="reviewed-day-card__title-row">
                                                <span class="reviewed-day-card__date" x-text="group.date_label"></span>
                                                <span
                                                    x-show="group.is_today"
                                                    class="reviewed-day-card__today"
                                                >{{ __('seo-content-ai::filament.articles_optimal.reviewed_today_suffix') }}</span>
                                                <span
                                                    class="reviewed-day-card__badge"
                                                    x-text="reviewedBadgeLabel(group.count)"
                                                ></span>
                                            </div>
                                        </div>
                                        <div class="reviewed-day-card__meta">
                                            <div class="reviewed-day-card__meta-item">
                                                <span class="reviewed-day-card__meta-label">{{ __('seo-content-ai::filament.articles_optimal.reviewed_first_review') }}</span>
                                                <span class="reviewed-day-card__meta-value" x-text="group.first_review"></span>
                                            </div>
                                            <div class="reviewed-day-card__meta-item">
                                                <span class="reviewed-day-card__meta-label">{{ __('seo-content-ai::filament.articles_optimal.reviewed_last_review') }}</span>
                                                <span class="reviewed-day-card__meta-value" x-text="group.last_review"></span>
                                            </div>
                                        </div>
                                        <svg
                                            class="reviewed-day-card__chevron"
                                            x-bind:class="isDateExpanded(group.date) ? 'is-open' : ''"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                                        </svg>
                                    </button>

                                    <div
                                        x-show="isDateExpanded(group.date)"
                                        x-collapse
                                        class="reviewed-day-card__body"
                                    >
                                        <div class="reviewed-article-list">
                                            <template x-for="article in group.articles" :key="article.id">
                                                <div class="reviewed-article-item">
                                                    <div class="reviewed-article-item__icon">
                                                        <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                                                    </div>
                                                    <div class="reviewed-article-item__content">
                                                        <div class="reviewed-article-item__title" x-text="article.title"></div>
                                                        <div class="reviewed-article-item__meta">
                                                            <span class="reviewed-article-item__status-dot" aria-hidden="true"></span>
                                                            <span>{{ __('seo-content-ai::filament.articles_optimal.reviewed_status_label') }}</span>
                                                            <span aria-hidden="true">·</span>
                                                            <span x-text="article.reviewed_time"></span>
                                                        </div>
                                                    </div>
                                                    <div class="reviewed-article-item__actions">
                                                        <a
                                                            x-bind:href="article.edit_url"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 bg-white ring-1 ring-gray-300 shadow-sm transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                                                        >
                                                            <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                                                            <span>{{ __('seo-content-ai::filament.articles_optimal.reviewed_action_view') }}</span>
                                                        </a>
                                                        <a
                                                            x-bind:href="article.edit_url"
                                                            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-white bg-primary-600 shadow-sm transition hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400"
                                                        >
                                                            <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                                            <span>{{ __('seo-content-ai::filament.articles_optimal.action_edit') }}</span>
                                                        </a>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>

    <button
        type="button"
        class="fixed right-0 top-24 z-40 rounded-l-lg border border-r-0 border-gray-200 bg-white px-2 py-3 text-gray-600 shadow dark:border-white/10 dark:bg-gray-900 dark:text-gray-300"
        x-show="activeTab === 'audit'"
        x-bind:style="sidebarCollapsed ? 'transform: translateX(0);' : 'transform: translateX(-30vw);'"
        x-on:click="sidebarCollapsed = ! sidebarCollapsed"
        x-bind:title="sidebarCollapsed ? 'Show sidebar' : 'Hide sidebar'"
    >
        <span x-show="! sidebarCollapsed">&gt;</span>
        <span x-show="sidebarCollapsed">&lt;</span>
    </button>

    <aside
        x-show="activeTab === 'audit'"
        class="overflow-y-auto border-l border-gray-200 bg-white p-4 shadow-xl transition-transform duration-300 dark:border-white/10 dark:bg-gray-900"
        style="position: fixed; right: 0; top: 0; bottom: 0; width: 30%; z-index: 30;"
        x-bind:style="sidebarCollapsed
            ? 'position: fixed; right: 0; top: 0; bottom: 0; width: 30%; z-index: 30; transform: translateX(100%);'
            : 'position: fixed; right: 0; top: 0; bottom: 0; width: 30%; z-index: 30; transform: translateX(0);'"
    >
        <div class="mt-20 space-y-4">
            <div class="flex justify-end">
                <x-filament::icon-button
                    type="button"
                    icon="heroicon-o-chevron-right"
                    color="gray"
                    x-on:click="sidebarCollapsed = true"
                    tooltip="Thu gọn sidebar"
                />
            </div>

            <div class="flex items-end gap-2">
                <div class="min-w-0 flex-1">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Content Project</label>
                    <x-select
                        x-model="sidebarProjectId"
                        x-on:change="$wire.selectSidebarProject($event.target.value)"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                    >
                        <option value="">-- Chọn project --</option>
                        @foreach ($projectOptions as $projectId => $projectLabel)
                            <option value="{{ $projectId }}">{{ $projectLabel }}</option>
                        @endforeach
                    </x-select>
                </div>
                <x-filament::icon-button type="button" icon="heroicon-o-plus" color="success" x-on:click="quickCreateOpen = true" tooltip="{{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}" />
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-white/10">
                <div class="border-b border-gray-100 px-3 py-2 text-sm font-semibold dark:border-white/10">
                    Bài viết trong project
                </div>
                <div wire:loading.class="opacity-50" wire:target="selectSidebarProject,assignArticleToSelectedProject,assignSelectedArticlesToSelectedProject,quickCreateSidebarProject" class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($sidebarArticles as $article)
                        <div class="px-3 py-2">
                            <div class="truncate text-sm font-medium">{{ $article['title'] }}</div>
                            <div class="text-xs text-gray-500">{{ $article['type'] }} · {{ $article['status'] }}</div>
                        </div>
                    @empty
                        <div class="px-3 py-8 text-center text-sm text-gray-500">
                            Chưa chọn project hoặc project chưa có bài.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </aside>

    <div x-show="assignOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">

    {{-- Loading overlay toàn trang cho các Livewire action --}}
    <div
        wire:loading
        wire:target="runScan,demoteToDraft,assignArticleToContentProject,assignArticleToSelectedProject,assignSelectedArticlesToSelectedProject,quickCreateSidebarProject,selectSidebarProject,resultsPaginator,nextPage,previousPage,gotoPage"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-white/70 dark:bg-gray-950/70"
        style="backdrop-filter: blur(2px);"
    >
        <div class="flex flex-col items-center gap-3 rounded-xl bg-white px-8 py-6 shadow-2xl dark:bg-gray-900">
            <svg class="h-10 w-10 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Đang xử lý…</span>
        </div>
    </div>

    <div x-show="assignOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50">
        <form x-on:submit.prevent="submitAssign()" class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl dark:bg-gray-900">
            <h3 class="text-base font-semibold">Assign to Content Project</h3>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="text-sm font-medium">Content Project</label>
                    <x-select x-model="assignProjectId" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">-- Chọn project --</option>
                        @foreach ($projectOptions as $projectId => $projectLabel)
                            <option value="{{ $projectId }}">{{ $projectLabel }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <label class="text-sm font-medium">{{ __('seo-content-ai::filament.projects.article_type') }}</label>
                    <x-select x-model="assignType" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                        @foreach ($assignTypeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div x-show="assignType === 'rewrite'">
                    <label class="text-sm font-medium">{{ __('seo-content-ai::filament.projects.rewrite_mode') }}</label>
                    <x-select x-model="rewriteMode" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                        @foreach ($rewriteModeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div x-show="assignType === 'rewrite' && rewriteMode === 'content'">
                    <label class="text-sm font-medium">{{ __('seo-content-ai::filament.projects.rewrite_notes') }}</label>
                    <textarea x-model="rewriteNotes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"></textarea>
                </div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <x-filament::button type="button" color="gray" x-on:click="assignOpen = false" x-bind:disabled="assignSubmitting">Cancel</x-filament::button>
                <x-filament::button type="submit" color="info" x-bind:disabled="assignSubmitting">
                    <span x-show="! assignSubmitting">Assign</span>
                    <span x-show="assignSubmitting" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Assigning...
                    </span>
                </x-filament::button>
            </div>
        </form>
    </div>

    <div x-show="quickCreateOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
        <form x-on:submit.prevent="submitQuickCreate()" class="w-full max-w-xl rounded-xl bg-white p-6 shadow-2xl dark:bg-gray-900">
            <h3 class="text-lg font-semibold leading-6">{{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}</h3>
            <div class="mt-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium">{{ __('seo-content-ai::filament.projects.domain') }}</label>
                    <x-select x-model="quickSiteId" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">-- Choose domain --</option>
                        @foreach ($projectSiteOptions as $siteId => $domain)
                            <option value="{{ $siteId }}">{{ $domain }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <label class="block text-sm font-medium">{{ __('seo-content-ai::filament.projects.assign_writer') }}</label>
                    <x-select x-model="quickWriterId" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">-- Choose writer --</option>
                        @foreach ($writerOptions as $writerId => $writerLabel)
                            <option value="{{ $writerId }}">{{ $writerLabel }}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <x-filament::button type="button" color="gray" x-on:click="quickCreateOpen = false" x-bind:disabled="quickCreateSubmitting">Cancel</x-filament::button>
                <x-filament::button type="submit" color="success" x-bind:disabled="quickCreateSubmitting">
                    <span x-show="! quickCreateSubmitting">Create</span>
                    <span x-show="quickCreateSubmitting" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Creating...
                    </span>
                </x-filament::button>
            </div>
        </form>
    </div>
</div>
