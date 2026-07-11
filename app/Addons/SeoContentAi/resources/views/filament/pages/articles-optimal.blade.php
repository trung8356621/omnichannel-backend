@php
    $paginator = $this->resultsPaginator;
    $projectOptions = $this->getContentProjectOptions();
    $projectSiteOptions = $this->getSidebarProjectSiteOptions();
    $writerOptions = $this->getWriterOptions();
    $assignTypeOptions = $this->getAssignTypeOptions();
    $rewriteModeOptions = $this->getRewriteModeOptions();
    $sidebarArticles = $this->getSidebarProjectArticles();
    $visibleIds = collect($paginator->items())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
@endphp

<div
    class="fi-page articles-optimal-page"
    x-data="{
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
        x-bind:style="! sidebarCollapsed ? 'padding-right: 31%;' : 'padding-right: 0;'"
    >
        <div class="space-y-6">
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
                    <span class="text-xs text-gray-500">Chá»n project á»Ÿ sidebar Ä‘á»ƒ bulk assign nhanh.</span>
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
    </div>

    <button
        type="button"
        class="fixed right-0 top-24 z-40 rounded-l-lg border border-r-0 border-gray-200 bg-white px-2 py-3 text-gray-600 shadow dark:border-white/10 dark:bg-gray-900 dark:text-gray-300"
x-bind:style="sidebarCollapsed ? 'transform: translateX(0);' : 'transform: translateX(-30vw);'"
        x-on:click="sidebarCollapsed = ! sidebarCollapsed"
        x-bind:title="sidebarCollapsed ? 'Show sidebar' : 'Hide sidebar'"
    >
        <span x-show="! sidebarCollapsed">&gt;</span>
        <span x-show="sidebarCollapsed">&lt;</span>
    </button>

    <aside
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
                    tooltip="Thu gá»n sidebar"
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
                        <option value="">-- Chá»n project --</option>
                        @foreach ($projectOptions as $projectId => $projectLabel)
                            <option value="{{ $projectId }}">{{ $projectLabel }}</option>
                        @endforeach
                    </x-select>
                </div>
                <x-filament::icon-button type="button" icon="heroicon-o-plus" color="success" x-on:click="quickCreateOpen = true" tooltip="{{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}" />
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-white/10">
                <div class="border-b border-gray-100 px-3 py-2 text-sm font-semibold dark:border-white/10">
                    BÃ i viáº¿t trong project
                </div>
                <div wire:loading.class="opacity-50" wire:target="selectSidebarProject,assignArticleToSelectedProject,assignSelectedArticlesToSelectedProject,quickCreateSidebarProject" class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($sidebarArticles as $article)
                        <div class="px-3 py-2">
                            <div class="truncate text-sm font-medium">{{ $article['title'] }}</div>
                            <div class="text-xs text-gray-500">{{ $article['type'] }} Â· {{ $article['status'] }}</div>
                        </div>
                    @empty
                        <div class="px-3 py-8 text-center text-sm text-gray-500">
                            ChÆ°a chá»n project hoáº·c project chÆ°a cÃ³ bÃ i.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </aside>

    <div x-show="assignOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">

    {{-- Loading overlay toÃ n trang cho cÃ¡c Livewire action --}}
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
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Äang xá»­ lÃ½â€¦</span>
        </div>
    </div>

    <div x-show="assignOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50">
        <form x-on:submit.prevent="submitAssign()" class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl dark:bg-gray-900">
            <h3 class="text-base font-semibold">Assign to Content Project</h3>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="text-sm font-medium">Content Project</label>
                    <x-select x-model="assignProjectId" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">-- Chá»n project --</option>
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
