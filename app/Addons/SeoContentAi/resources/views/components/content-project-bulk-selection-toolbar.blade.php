@props([
    'selectedCount' => 0,
])

@if ((int) $selectedCount > 0)
    <div
        {{ $attributes->class([
            'mt-3 flex flex-col gap-2 rounded-lg border border-primary-200/80 bg-primary-50/50 p-3 dark:border-primary-500/30 dark:bg-primary-500/10',
        ]) }}
        role="toolbar"
        aria-label="Bulk selection actions"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                {{ (int) $selectedCount }} selected
            </span>
            <button
                type="button"
                wire:click="clearSelection"
                class="text-xs font-semibold text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-300"
            >
                {{ __('seo-content-ai::filament.projects.queue_clear_selection') }}
            </button>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:w-auto sm:self-center">Content</span>
            <button type="button" wire:click="generateSelected" wire:loading.attr="disabled" wire:target="generateSelected" class="fi-btn fi-btn-color-success fi-size-sm">
                <span wire:loading.remove wire:target="generateSelected">Generate pending</span>
                <span wire:loading wire:target="generateSelected" class="inline-flex items-center gap-1"><x-filament::loading-indicator class="h-4 w-4" />…</span>
            </button>
            <button type="button" wire:click="bulkRegenOutline" wire:confirm="Regenerate outline for selection?" class="fi-btn fi-btn-color-gray fi-size-sm">Regen outline</button>
            <button type="button" wire:click="bulkRegenArticle" wire:confirm="Regenerate article for selection?" class="fi-btn fi-btn-color-gray fi-size-sm">Regen article</button>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:w-auto sm:self-center">Review</span>
            <button type="button" wire:click="startReviewSelected" class="fi-btn fi-btn-color-warning fi-size-sm">Start review</button>
            <button type="button" wire:click="approveSelected" class="fi-btn fi-btn-color-success fi-size-sm">Approve</button>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:w-auto sm:self-center">Publishing</span>
            <button type="button" wire:click="bulkSchedule" class="fi-btn fi-btn-color-primary fi-size-sm">Schedule</button>
            <button type="button" @click="$dispatch('open-auto-schedule')" class="fi-btn fi-btn-color-primary fi-size-sm">Auto schedule</button>
            <button type="button" wire:click="bulkUnschedule" class="fi-btn fi-btn-color-gray fi-size-sm">Unschedule</button>
            <button type="button" wire:click="bulkPublishNow" wire:confirm="Publish now?" class="fi-btn fi-btn-color-primary fi-size-sm">Publish now</button>
            <button type="button" wire:click="bulkRetryPublish" class="fi-btn fi-btn-color-warning fi-size-sm">Retry publish</button>
            <button type="button" wire:click="bulkSkipPublish" class="fi-btn fi-btn-color-gray fi-size-sm">Skip</button>
            <button type="button" wire:click="bulkCancelPublish" wire:confirm="Cancel publishing?" class="fi-btn fi-btn-color-danger fi-size-sm">Cancel</button>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-danger-600 sm:w-auto sm:self-center dark:text-danger-400">Lifecycle</span>
            <button
                type="button"
                wire:click="archiveSelected"
                wire:confirm="{{ __('seo-content-ai::filament.projects.archive_selected_confirm', ['count' => (int) $selectedCount]) }}"
                class="fi-btn fi-btn-color-danger fi-size-sm"
            >
                {{ __('seo-content-ai::filament.projects.archive_selected') }}
            </button>
        </div>
    </div>
@endif
