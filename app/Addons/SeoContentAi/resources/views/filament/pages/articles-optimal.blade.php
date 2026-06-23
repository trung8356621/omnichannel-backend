<div class="fi-page articles-optimal-page">
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
                        <select
                            wire:model.live="filterSiteId"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            <option value="">{{ __('seo-content-ai::filament.articles_optimal.domain_all') }}</option>
                            @foreach ($this->getSiteFilterOptions() as $siteId => $domainLabel)
                                <option value="{{ $siteId }}">{{ $domainLabel }}</option>
                            @endforeach
                        </select>
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
            @elseif ($this->resultsPaginator->total() === 0)
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.articles_optimal.empty_results') }}
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_title') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_domain') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_warnings') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_score') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('seo-content-ai::filament.articles_optimal.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($this->resultsPaginator as $row)
                                <tr wire:key="article-optimal-{{ $row['id'] }}">
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
                                            <x-filament::button tag="a" href="{{ $row['edit_url'] }}" size="sm" color="gray">
                                                {{ __('seo-content-ai::filament.articles_optimal.action_edit') }}
                                            </x-filament::button>
                                            <x-filament::button
                                                size="sm"
                                                color="warning"
                                                wire:click="demoteToDraft({{ $row['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="demoteToDraft"
                                            >
                                                {{ __('seo-content-ai::filament.articles_optimal.action_demote_draft') }}
                                            </x-filament::button>
                                            <x-filament::button
                                                size="sm"
                                                color="info"
                                                wire:click="openAssignModal({{ $row['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openAssignModal"
                                            >
                                                {{ __('seo-content-ai::filament.articles_optimal.action_assign_project') }}
                                            </x-filament::button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $this->resultsPaginator->links() }}
                </div>
            @endif
        </x-filament::section>
    </div>
</div>

{{-- Assign to Content Project Modal --}}
@if ($assignModalOpen)
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        wire:click.self="closeAssignModal"
    >
        <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('seo-content-ai::filament.article_list.assign_to_content_project') }}
            </h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ __('seo-content-ai::filament.article_list.assign_to_content_project_description') }}
            </p>

            <form wire:submit="submitAssignToContentProject" class="mt-4 space-y-4">
                {{-- Task type --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.projects.article_type') }}
                    </label>
                    <select
                        wire:model.live="assignType"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                    >
                        @foreach ($this->getAssignTypeOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Rewrite mode --}}
                @if ($this->shouldShowRewriteMode())
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.projects.rewrite_mode') }}
                        </label>
                        <select
                            wire:model.live="assignRewriteMode"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        >
                            @foreach ($this->getAssignRewriteModeOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Rewrite notes --}}
                @if ($this->shouldShowRewriteNotes())
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.projects.rewrite_notes') }}
                        </label>
                        <textarea
                            wire:model="assignRewriteNotes"
                            rows="3"
                            placeholder="{{ __('seo-content-ai::filament.projects.rewrite_notes_placeholder') }}"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                        ></textarea>
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="closeAssignModal"
                    >
                        {{ __('seo-content-ai::filament.articles_optimal.cancel') }}
                    </x-filament::button>
                    <x-filament::button type="submit" color="info">
                        {{ __('seo-content-ai::filament.article_list.assign') }}
                    </x-filament::button>
                </div>
            </form>
        </div>
    </div>
@endif
