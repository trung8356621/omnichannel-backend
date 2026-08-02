@php
    /** @var \App\Addons\SeoContentAi\Filament\Pages\PublishingQueueHub $this */
    $payload = $this->queuePayload;
    $stats = $payload['stats'] ?? [];
    $rows = $payload['rows'] ?? [];
    $active = $this->stateFilter;
    $project = $this->project;
    $hasProject = $project instanceof \App\Addons\SeoContentAi\Models\SeoProject;
    $projects = $this->selectableProjects;
    $selectedCount = count($this->selectedTaskIds);
    $hasActiveFilters = trim((string) $this->search) !== '' || trim((string) $this->stateFilter) !== '';
    $kpiCards = [
        ['key' => 'unscheduled', 'card' => 'unscheduled', 'filter' => 'unscheduled', 'label' => 'Unscheduled', 'hint' => 'In queue, not scheduled yet', 'value' => (int) ($stats['unscheduled'] ?? 0)],
        ['key' => 'scheduled', 'card' => 'scheduled', 'filter' => 'scheduled', 'label' => 'Scheduled', 'hint' => 'Waiting for due time', 'value' => (int) ($stats['scheduled'] ?? 0)],
        ['key' => 'publishing', 'card' => 'publishing', 'filter' => 'publishing', 'label' => 'Publishing', 'hint' => 'Waiting / processing publish', 'value' => (int) ($stats['publishing'] ?? 0)],
        ['key' => 'published', 'card' => 'published', 'filter' => 'published', 'label' => 'Published', 'hint' => 'Publisher confirmed on WordPress', 'value' => (int) ($stats['published'] ?? 0)],
        ['key' => 'failed', 'card' => 'failed', 'filter' => 'failed', 'label' => 'Failed', 'hint' => 'Publish failed', 'value' => (int) ($stats['failed'] ?? 0)],
    ];
@endphp

<x-filament-panels::page>
    <div
        class="space-y-4"
        x-data="{
            autoOpen: false,
            quickOpen: false,
            // Shared with content-project-item-meta / -item-thumbnail (CP ops has an
            // optimistic-claim variant of this method); the hub just navigates.
            openNeedsReviewArticle(taskId, isNeedsReview, url) {
                if (typeof url === 'string' && url !== '') {
                    window.location.href = url;
                }
            },
        }"
    >
        <div class="flex flex-wrap items-center gap-2">
            <label class="text-xs font-medium text-gray-500 dark:text-gray-400" for="pq-hub-project">
                {{ __('seo-content-ai::filament.projects.publishing_queue_hub_select_project') }}
            </label>
            <x-select id="pq-hub-project" wire:model.live="projectId" class="!w-64">
                <option value="">{{ __('seo-content-ai::filament.projects.publishing_queue_hub_all_projects') }}</option>
                @foreach ($projects as $p)
                    <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                @endforeach
            </x-select>
            @unless ($hasProject)
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.projects.publishing_queue_hub_actions_disabled_hint') }}
                </span>
            @endunless
        </div>

        <x-seo-content-ai::content-project-summary-cards
            :cards="$kpiCards"
            :active="$active"
            wire-method="applyStateFilter"
            aria-label="Publishing Queue summary"
            loading-targets="applyStateFilter,clearFilters,search,projectId,stateFilter"
        />

        <div @class(['opacity-50 pointer-events-none' => $this->bulkRunning])>
            <x-seo-content-ai::content-project-filter-toolbar variant="publishing_queue" />

            @if ($hasProject)
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" @click="autoOpen = true" class="fi-btn fi-btn-color-primary fi-size-sm">Auto</button>
                    <button type="button" @click="quickOpen = true" class="fi-btn fi-btn-color-primary fi-size-sm">Quick Mode</button>
                </div>
                <x-seo-content-ai::content-project-bulk-selection-toolbar
                    variant="publishing_queue"
                    :selected-count="$selectedCount"
                />
            @endif
        </div>

        <x-seo-content-ai::content-project-items-list
            variant="publishing_queue"
            :rows="$rows"
            :has-active-filters="$hasActiveFilters"
            :show-checkbox="$hasProject"
            :use-row-visibility="false"
        />

        @if ($hasProject)
            <div
                x-show="autoOpen"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-4 shadow-xl dark:bg-gray-900" @click.outside="autoOpen = false">
                    <h3 class="mb-2 text-sm font-semibold">Auto schedule</h3>
                    <p class="mb-3 text-xs text-gray-500">Distribute unscheduled items across the project month window.</p>
                    <div class="mb-3 grid grid-cols-2 gap-2">
                        <x-select wire:model="autoDayStart" class="!w-full">
                            @foreach (['08:00','09:00','10:00','11:00'] as $t)
                                <option value="{{ $t }}">Day start {{ $t }}</option>
                            @endforeach
                        </x-select>
                        <x-select wire:model="autoDayEnd" class="!w-full">
                            @foreach (['16:00','17:00','18:00','19:00'] as $t)
                                <option value="{{ $t }}">Day end {{ $t }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="text-sm" @click="autoOpen = false">Cancel</button>
                        <button type="button" class="fi-btn fi-btn-color-primary fi-size-sm" wire:click="runProjectMonthAutoSchedule" @click="autoOpen = false">Run Auto</button>
                    </div>
                </div>
            </div>

            <div
                x-show="quickOpen"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            >
                <div class="w-full max-w-md rounded-xl bg-white p-4 shadow-xl dark:bg-gray-900" @click.outside="quickOpen = false">
                    <h3 class="mb-2 text-sm font-semibold">Quick Mode</h3>
                    <p class="mb-3 text-xs text-gray-500">Deadline recovery — even distribution with safe minimum interval. Not Dev/Test mode.</p>
                    <div class="mb-3 space-y-2">
                        <x-select wire:model="quickDays" class="!w-full">
                            @foreach ([1,2,3,5,7] as $d)
                                <option value="{{ $d }}">{{ $d }} day{{ $d > 1 ? 's' : '' }}</option>
                            @endforeach
                        </x-select>
                        <x-select wire:model="quickStartTime" class="!w-full">
                            @foreach (['08:00','09:00','10:00','11:00','12:00'] as $t)
                                <option value="{{ $t }}">Start {{ $t }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="text-sm" @click="quickOpen = false">Cancel</button>
                        <button type="button" class="fi-btn fi-btn-color-primary fi-size-sm" wire:click="runQuickSchedule" @click="quickOpen = false">Run Quick Mode</button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <x-seo-content-ai::content-project-ops-styles />
</x-filament-panels::page>
