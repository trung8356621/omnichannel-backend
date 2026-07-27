<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data="{
            tab: @entangle('activeTab').live,
            switchTab(name) {
                this.tab = name;
                $wire.switchTab(name);
            }
        }"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-gray-500 dark:text-gray-400">
                <span class="font-mono">{{ $workspace['workspace_ref'] ?? '' }}</span>
                @if (!empty($workspace['is_archived']))
                    <span class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.keyword_intelligence.archived_badge') }}
                    </span>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <x-filament::button
                    type="button"
                    color="primary"
                    icon="heroicon-o-sparkles"
                    :disabled="!empty($workspace['is_archived'])"
                    wire:click="analyzeWorkspace"
                    wire:loading.attr="disabled"
                    wire:target="analyzeWorkspace"
                >
                    <span wire:loading.remove wire:target="analyzeWorkspace">{{ __('seo-content-ai::filament.keyword_intelligence.analyze') }}</span>
                    <span wire:loading wire:target="analyzeWorkspace" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        {{ __('seo-content-ai::filament.keyword_intelligence.analyzing') }}
                    </span>
                </x-filament::button>
                @if (empty($workspace['is_archived']))
                    <x-filament::button
                        type="button"
                        color="danger"
                        icon="heroicon-o-archive-box"
                        x-on:click="if (confirm('{{ __('seo-content-ai::filament.keyword_intelligence.archive_confirm') }}')) { $wire.archiveWorkspace() }"
                        wire:loading.attr="disabled"
                        wire:target="archiveWorkspace"
                    >
                        {{ __('seo-content-ai::filament.keyword_intelligence.archive') }}
                    </x-filament::button>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
            @foreach ([
                'overview' => __('seo-content-ai::filament.keyword_intelligence.tab_overview'),
                'keywords' => __('seo-content-ai::filament.keyword_intelligence.tab_keywords'),
                'clusters' => __('seo-content-ai::filament.keyword_intelligence.tab_clusters'),
                'topical_map' => __('seo-content-ai::filament.keyword_intelligence.tab_topical_map'),
                'cannibalization' => __('seo-content-ai::filament.keyword_intelligence.tab_cannibalization'),
            ] as $name => $label)
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium"
                    :class="tab === '{{ $name }}'
                        ? 'bg-primary-600 text-white'
                        : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'"
                    x-on:click="switchTab('{{ $name }}')"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Overview --}}
        <div x-show="tab === 'overview'" x-cloak class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.keyword_intelligence.col_status') }}</x-slot>
                    <div class="text-lg font-semibold capitalize">{{ $workspace['status'] ?? '—' }}</div>
                    <div class="text-xs text-gray-500">{{ $workspace['language'] ?? '—' }} / {{ $workspace['country'] ?? '—' }}</div>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.keyword_intelligence.col_keywords') }}</x-slot>
                    <div class="text-lg font-semibold">{{ $workspace['keyword_count'] ?? 0 }}</div>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.keyword_intelligence.col_clusters') }}</x-slot>
                    <div class="text-lg font-semibold">{{ $workspace['cluster_count'] ?? 0 }}</div>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.keyword_intelligence.col_last_analyzed') }}</x-slot>
                    <div class="text-sm">{{ $workspace['last_analyzed_at'] ?? '—' }}</div>
                </x-filament::section>
            </div>

            <x-filament::section>
                <x-slot name="heading">{{ __('seo-content-ai::filament.keyword_intelligence.import_heading') }}</x-slot>
                <div class="space-y-3">
                    <x-filament::input.wrapper>
                        <textarea
                            wire:model="importText"
                            rows="6"
                            class="fi-input block w-full border-none bg-transparent p-0 text-sm focus:ring-0"
                            placeholder="{{ __('seo-content-ai::filament.keyword_intelligence.import_placeholder') }}"
                        ></textarea>
                    </x-filament::input.wrapper>
                    <div class="flex flex-wrap items-center gap-4 text-sm">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model="importAsPreview" class="rounded border-gray-300">
                            {{ __('seo-content-ai::filament.keyword_intelligence.import_preview_only') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model="importKeepDuplicates" class="rounded border-gray-300">
                            {{ __('seo-content-ai::filament.keyword_intelligence.import_keep_duplicates') }}
                        </label>
                        <x-filament::button
                            type="button"
                            :disabled="!empty($workspace['is_archived'])"
                            wire:click="importKeywords"
                            wire:loading.attr="disabled"
                            wire:target="importKeywords"
                        >
                            {{ __('seo-content-ai::filament.keyword_intelligence.import_submit') }}
                        </x-filament::button>
                    </div>

                    @if ($importResult)
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-700 dark:bg-gray-900">
                            <pre class="whitespace-pre-wrap break-words">{{ json_encode($importResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        </div>

        {{-- Keywords --}}
        <div x-show="tab === 'keywords'" x-cloak class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <x-filament::button type="button" size="sm" color="success" wire:click="approveSelectedKeywords(true)" wire:loading.attr="disabled" wire:target="approveSelectedKeywords">
                    {{ __('seo-content-ai::filament.keyword_intelligence.approve') }}
                </x-filament::button>
                <x-filament::button type="button" size="sm" color="danger" wire:click="approveSelectedKeywords(false)" wire:loading.attr="disabled" wire:target="approveSelectedKeywords">
                    {{ __('seo-content-ai::filament.keyword_intelligence.reject') }}
                </x-filament::button>
            </div>
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_keyword') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_intent') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_volume') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_priority') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_review_status') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_cluster') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($keywords as $k)
                            <tr>
                                <td class="px-3 py-2"><input type="checkbox" wire:model="selectedKeywordRefs" value="{{ $k['keyword_ref'] }}" class="rounded border-gray-300"></td>
                                <td class="px-3 py-2">{{ $k['keyword'] }}</td>
                                <td class="px-3 py-2">{{ $k['search_intent'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $k['search_volume'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $k['priority_score'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $k['review_status'] === 'approved' ? 'bg-success-100 text-success-700' : ($k['review_status'] === 'rejected' ? 'bg-danger-100 text-danger-700' : 'bg-gray-100 text-gray-600') }}">
                                        {{ $k['review_status'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $k['cluster_ref'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">{{ __('seo-content-ai::filament.keyword_intelligence.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Clusters --}}
        <div x-show="tab === 'clusters'" x-cloak class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <x-filament::button type="button" size="sm" color="success" wire:click="approveSelectedClusters(true)" wire:loading.attr="disabled" wire:target="approveSelectedClusters">
                    {{ __('seo-content-ai::filament.keyword_intelligence.approve') }}
                </x-filament::button>
                <x-filament::button type="button" size="sm" color="danger" wire:click="approveSelectedClusters(false)" wire:loading.attr="disabled" wire:target="approveSelectedClusters">
                    {{ __('seo-content-ai::filament.keyword_intelligence.exclude') }}
                </x-filament::button>
                <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-squares-plus" wire:click="buildTopicalMap" wire:loading.attr="disabled" wire:target="buildTopicalMap">
                    {{ __('seo-content-ai::filament.keyword_intelligence.build_map') }}
                </x-filament::button>
                <x-filament::button type="button" size="sm" color="warning" wire:click="previewConvert" wire:loading.attr="disabled" wire:target="previewConvert">
                    {{ __('seo-content-ai::filament.keyword_intelligence.preview_convert') }}
                </x-filament::button>
                <x-filament::button type="button" size="sm" color="primary" wire:click="convertToContentProject" wire:loading.attr="disabled" wire:target="convertToContentProject">
                    {{ __('seo-content-ai::filament.keyword_intelligence.convert') }}
                </x-filament::button>
            </div>

            @if ($convertPreview)
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.keyword_intelligence.preview_heading') }}</x-slot>
                    <div class="space-y-2 text-sm">
                        <div class="flex gap-4">
                            <span>{{ __('seo-content-ai::filament.keyword_intelligence.eligible_clusters') }}: <strong>{{ $convertPreview['eligible_clusters'] ?? 0 }}</strong> / {{ $convertPreview['total_clusters'] ?? 0 }}</span>
                            <span>{{ __('seo-content-ai::filament.keyword_intelligence.total_keywords') }}: <strong>{{ $convertPreview['total_keywords'] ?? 0 }}</strong></span>
                        </div>
                        @if (!empty($convertPreview['warnings']))
                            <ul class="list-disc pl-5 text-xs text-warning-700">
                                @foreach ($convertPreview['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($convertConfirmationToken)
                            <p class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword_intelligence.confirmation_needed') }}</p>
                        @endif
                    </div>
                </x-filament::section>
            @endif

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_name') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_status') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_intent') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_keywords') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_priority') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_content_type') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_project') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($clusters as $c)
                            <tr>
                                <td class="px-3 py-2"><input type="checkbox" wire:model="selectedClusterRefs" value="{{ $c['cluster_ref'] }}" class="rounded border-gray-300"></td>
                                <td class="px-3 py-2">{{ $c['name'] }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $c['status'] === 'approved' ? 'bg-success-100 text-success-700' : ($c['status'] === 'excluded' ? 'bg-danger-100 text-danger-700' : 'bg-gray-100 text-gray-600') }}">
                                        {{ $c['status'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">{{ $c['search_intent'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $c['keyword_count'] }}</td>
                                <td class="px-3 py-2">{{ $c['priority_score'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $c['suggested_content_type'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $c['content_project_ref'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">{{ __('seo-content-ai::filament.keyword_intelligence.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Topical Map --}}
        <div x-show="tab === 'topical_map'" x-cloak class="space-y-4">
            @if ($topicalMap)
                <x-filament::section>
                    <x-slot name="heading">{{ $topicalMap['snapshot']['root']['name'] ?? __('seo-content-ai::filament.keyword_intelligence.tab_topical_map') }}</x-slot>
                    <div class="mb-3 text-xs text-gray-500">
                        {{ __('seo-content-ai::filament.keyword_intelligence.map_version') }} v{{ $topicalMap['version'] }} — {{ $topicalMap['generated_at'] }}
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($topicalMap['snapshot']['pillars'] ?? [] as $pillar)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <div class="font-medium">{{ $pillar['name'] }}</div>
                                <div class="mt-1 text-xs text-gray-500">
                                    {{ $pillar['cluster_count'] }} {{ __('seo-content-ai::filament.keyword_intelligence.col_clusters') }} ·
                                    {{ $pillar['keyword_count'] }} {{ __('seo-content-ai::filament.keyword_intelligence.col_keywords') }} ·
                                    {{ $pillar['total_search_volume'] }} vol
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @else
                <x-filament::section>
                    <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword_intelligence.no_topical_map') }}</p>
                </x-filament::section>
            @endif
        </div>

        {{-- Cannibalization --}}
        <div x-show="tab === 'cannibalization'" x-cloak class="space-y-4">
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_type') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_target') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_risk') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_articles') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.keyword_intelligence.col_recommendation') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($cannibalizationRisks as $risk)
                            <tr>
                                <td class="px-3 py-2">{{ $risk['type'] }}</td>
                                <td class="px-3 py-2">{{ $risk['keyword'] ?? $risk['cluster_name'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $risk['risk_level'] === 'critical' ? 'bg-danger-100 text-danger-700' : ($risk['risk_level'] === 'high' ? 'bg-warning-100 text-warning-700' : 'bg-gray-100 text-gray-600') }}">
                                        {{ $risk['risk_level'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">{{ count($risk['article_refs'] ?? []) }}</td>
                                <td class="px-3 py-2 text-xs">{{ $risk['recommended_action'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">{{ __('seo-content-ai::filament.keyword_intelligence.no_cannibalization') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
