@props([
    'showTest' => false,
])

@php
    $progressTemplate = __('seo-content-ai::filament.domain.sync_incremental_progress', [
        'done' => '__DONE__',
        'total' => '__TOTAL__',
    ]);
@endphp

<div
    class="seo-sync-actions space-y-2"
    x-data="{
        done: @js($incrementalSyncProgress),
        total: @js($incrementalSyncTotal),
        running: @js($incrementalSyncRunning),
        template: @js($progressTemplate),
        progressLabel() {
            return this.template
                .replace('__DONE__', String(this.done))
                .replace('__TOTAL__', String(this.total));
        },
    }"
    x-on:incremental-sync-progress.window="
        done = $event.detail.done ?? 0;
        total = $event.detail.total ?? 0;
        running = $event.detail.running ?? false;
    "
>
    <p
        x-show="total > 0"
        x-cloak
        class="text-sm font-medium text-primary-600 dark:text-primary-400"
        x-text="progressLabel()"
    ></p>

    <p
        x-show="running || @js($keywordResyncRunning)"
        x-cloak
        class="text-xs text-gray-500 dark:text-gray-400"
    >
        @if ($keywordResyncRunning)
            {{ __('seo-content-ai::filament.keyword.resync_linked_running_hint') }}
        @else
            {{ __('seo-content-ai::filament.domain.sync_incremental_background_hint') }}
        @endif
    </p>

    <div class="flex flex-wrap gap-2">
        <x-filament::button
            type="button"
            color="success"
            icon="heroicon-o-arrow-down-tray"
            wire:click="runIncrementalSyncAction"
            wire:loading.attr="disabled"
            wire:target="runIncrementalSyncAction"
            :disabled="$incrementalSyncRunning || $keywordResyncRunning"
        >
            <span wire:loading.remove wire:target="runIncrementalSyncAction">
                @if ($incrementalSyncRunning)
                    {{ __('seo-content-ai::filament.domain.sync_incremental_running') }}
                @elseif ($incrementalSyncResumable)
                    {{ __('seo-content-ai::filament.domain.sync_incremental_resume') }}
                @else
                    {{ __('seo-content-ai::filament.domain.sync_incremental') }}
                @endif
            </span>
            <span wire:loading wire:target="runIncrementalSyncAction">
                {{ __('seo-content-ai::filament.domain.sync_incremental_preparing') }}
            </span>
        </x-filament::button>

        <x-filament::button
            type="button"
            color="danger"
            icon="heroicon-o-arrow-path"
            wire:click="runRescrapeKeywordsAction"
            wire:confirm="{{ __('seo-content-ai::filament.keyword.resync_linked_confirm') }}"
            wire:loading.attr="disabled"
            wire:target="runRescrapeKeywordsAction"
            :disabled="$incrementalSyncRunning || $keywordResyncRunning"
        >
            <span wire:loading.remove wire:target="runRescrapeKeywordsAction">
                @if ($keywordResyncRunning)
                    {{ __('seo-content-ai::filament.keyword.resync_linked_running') }}
                @else
                    {{ __('seo-content-ai::filament.keyword.resync_linked') }}
                @endif
            </span>
            <span wire:loading wire:target="runRescrapeKeywordsAction">
                {{ __('seo-content-ai::filament.keyword.resync_linked_dispatching') }}
            </span>
        </x-filament::button>

        <x-filament::button
            type="button"
            color="warning"
            icon="heroicon-o-link-slash"
            wire:click="runAuditLinkStatusAction"
            wire:loading.attr="disabled"
            wire:target="runAuditLinkStatusAction"
            :disabled="$incrementalSyncRunning || $keywordResyncRunning"
        >
            <span wire:loading.remove wire:target="runAuditLinkStatusAction">
                {{ __('seo-content-ai::filament.domain.audit_link_status') }}
            </span>
            <span wire:loading wire:target="runAuditLinkStatusAction">
                {{ __('seo-content-ai::filament.domain.audit_link_status_dispatching') }}
            </span>
        </x-filament::button>

        @if ($showTest)
            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-o-bug-ant"
                wire:click="mountAction('test_sync_data')"
                wire:loading.attr="disabled"
                wire:target="runIncrementalSyncAction, mountAction('test_sync_data')"
                :disabled="$incrementalSyncRunning"
            >
                {{ __('seo-content-ai::filament.domain.test_sync_debug') }}
            </x-filament::button>
        @endif
    </div>
</div>
