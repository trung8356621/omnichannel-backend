@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Addons\SeoContentAi\Models\SeoProjectArchive> $archives */
    $archives = $this->archives;
    $siteFilterOptions = $this->getSiteFilterOptions();
    $ownerFilterOptions = $this->getOwnerFilterOptions();
    $archivedByFilterOptions = $this->getArchivedByFilterOptions();
    $monthFilterOptions = $this->getMonthFilterOptions();
    $yearFilterOptions = $this->getYearFilterOptions();
    $showSiteFilter = count($siteFilterOptions) > 1;
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __('seo-content-ai::filament.projects.archive_dashboard_heading') }}
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ __('seo-content-ai::filament.projects.archive_dashboard_description') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
            <button
                type="button"
                wire:click="setActiveTab('projects')"
                @class([
                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm' => $activeTab === 'projects',
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' => $activeTab !== 'projects',
                ])
            >
                {{ __('seo-content-ai::filament.projects.archive_tab_projects') }}
            </button>
            <button
                type="button"
                wire:click="setActiveTab('legacy')"
                @class([
                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm' => $activeTab === 'legacy',
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' => $activeTab !== 'legacy',
                ])
            >
                {{ __('seo-content-ai::filament.projects.archive_tab_legacy') }}
            </button>
        </div>

        @if ($activeTab === 'projects')
            <div class="space-y-4">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div class="md:col-span-2 xl:col-span-3">
                        <label class="sr-only" for="archive-project-search">{{ __('seo-content-ai::filament.projects.archive_search_placeholder') }}</label>
                        <input
                            id="archive-project-search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                            placeholder="{{ __('seo-content-ai::filament.projects.archive_search_placeholder') }}"
                            autocomplete="off"
                        >
                    </div>

                    @if ($showSiteFilter)
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-site-filter">
                                {{ __('seo-content-ai::filament.article_list.domain') }}
                            </label>
                            <x-select id="archive-project-site-filter" wire:model.live="siteFilter" class="w-full rounded-lg text-sm">
                                <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_domain_all') }}</option>
                                @foreach ($siteFilterOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-month-filter">
                            {{ __('seo-content-ai::filament.projects.month') }}
                        </label>
                        <x-select id="archive-project-month-filter" wire:model.live="monthFilter" class="w-full rounded-lg text-sm">
                            <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_month_all') }}</option>
                            @foreach ($monthFilterOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-year-filter">
                            {{ __('seo-content-ai::filament.projects.year') }}
                        </label>
                        <x-select id="archive-project-year-filter" wire:model.live="yearFilter" class="w-full rounded-lg text-sm">
                            <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_year_all') }}</option>
                            @foreach ($yearFilterOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    @if ($ownerFilterOptions !== [])
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-owner-filter">
                                {{ __('seo-content-ai::filament.projects.owner') }}
                            </label>
                            <x-select id="archive-project-owner-filter" wire:model.live="ownerFilter" class="w-full rounded-lg text-sm">
                                <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_owner_all') }}</option>
                                @foreach ($ownerFilterOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif

                    @if ($archivedByFilterOptions !== [])
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="archive-project-archived-by-filter">
                                {{ __('seo-content-ai::filament.projects.archive_filter_archived_by') }}
                            </label>
                            <x-select id="archive-project-archived-by-filter" wire:model.live="archivedByFilter" class="w-full rounded-lg text-sm">
                                <option value="">{{ __('seo-content-ai::filament.projects.archive_filter_archived_by_all') }}</option>
                                @foreach ($archivedByFilterOptions as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800/60">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.name') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.owner') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.article_list.domain') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.month') }}</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_total') }}</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_completed') }}</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_approved') }}</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_synced') }}</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_avg_seo') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_archived_at') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_archived_by') }}</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($archives as $archive)
                                @php
                                    $ownerName = trim((string) ($archive->owner?->display_name ?? $archive->owner?->name ?? ''));
                                    $archivedByName = trim((string) ($archive->archivedByUser?->display_name ?? $archive->archivedByUser?->name ?? ''));
                                    $domain = trim((string) ($archive->site?->domain ?? ''));
                                    $month = (int) ($archive->project_month ?? 0);
                                    $year = (int) ($archive->project_year ?? 0);
                                    $period = ($month > 0 && $year > 0) ? sprintf('%02d/%d', $month, $year) : '—';
                                    $avgSeo = $archive->average_seo_score;
                                @endphp
                                <tr wire:key="archive-row-{{ $archive->id }}">
                                    <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $archive->project_name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $ownerName !== '' ? $ownerName : '—' }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $domain !== '' ? $domain : '—' }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $period }}</td>
                                    <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ (int) ($archive->total_articles ?? $archive->articles_count ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ (int) ($archive->completed_articles ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ (int) ($archive->approved_articles ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ (int) ($archive->synced_articles ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ $avgSeo !== null ? number_format((float) $avgSeo, 2) : '—' }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ \App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource::formatTaskTimestamp($archive->archived_at) }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $archivedByName !== '' ? $archivedByName : '—' }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a
                                                href="{{ \App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource::getUrl('archive-preview', ['archive' => $archive->id]) }}"
                                                class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-800"
                                            >
                                                {{ __('seo-content-ai::filament.projects.archive_preview') }}
                                            </a>
                                            <button
                                                type="button"
                                                wire:click="exportArchive({{ $archive->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="exportArchive({{ $archive->id }})"
                                                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-primary-300 hover:bg-primary-50 disabled:opacity-50 dark:text-primary-300 dark:ring-primary-500/40 dark:hover:bg-primary-500/10"
                                            >
                                                <x-filament::loading-indicator class="h-3.5 w-3.5" wire:loading wire:target="exportArchive({{ $archive->id }})" />
                                                <span wire:loading.remove wire:target="exportArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_export') }}</span>
                                                <span wire:loading wire:target="exportArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_export_running') }}</span>
                                            </button>
                                            @if ($this->canRestoreArchives())
                                                <button
                                                    type="button"
                                                    wire:click="restoreArchive({{ $archive->id }})"
                                                    wire:confirm="{{ __('seo-content-ai::filament.projects.archive_restore_confirm') }}"
                                                    wire:loading.attr="disabled"
                                                    wire:target="restoreArchive({{ $archive->id }})"
                                                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-warning-700 ring-1 ring-warning-300 hover:bg-warning-50 disabled:opacity-50 dark:text-warning-300 dark:ring-warning-500/40 dark:hover:bg-warning-500/10"
                                                >
                                                    <x-filament::loading-indicator class="h-3.5 w-3.5" wire:loading wire:target="restoreArchive({{ $archive->id }})" />
                                                    <span wire:loading.remove wire:target="restoreArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_restore') }}</span>
                                                    <span wire:loading wire:target="restoreArchive({{ $archive->id }})">{{ __('seo-content-ai::filament.projects.archive_restore_running') }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.projects.archive_projects_empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($archives->hasPages())
                    <div>
                        {{ $archives->links() }}
                    </div>
                @endif
            </div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                {{ __('seo-content-ai::filament.projects.archive_legacy_banner') }}
            </div>

            @include('seo-content-ai::filament.resources.seo-project-resource.partials.archive-dashboard', [
                'siteId' => (int) ($this->siteId ?? 0),
                'siteIds' => $this->scopedSiteIds,
                'canReopen' => $this->canReopenArchivedArticles(),
            ])
        @endif
    </div>
</x-filament-panels::page>
