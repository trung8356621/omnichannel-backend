@props([
    'showTest' => false,
])

@php
    $syncDisabled = $incrementalSyncRunning || $metadataSyncRunning || $keywordResyncRunning;
@endphp

<div class="seo-sync-actions divide-y divide-gray-200 dark:divide-white/10">
    {{-- Đồng bộ bổ sung --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="success"
                icon="heroicon-o-arrow-down-tray"
                class="w-full justify-center"
                wire:click="runIncrementalSyncAction"
                wire:loading.attr="disabled"
                wire:target="runIncrementalSyncAction"
                :disabled="$syncDisabled"
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
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $incrementalSyncStatus,
                'message' => $incrementalSyncStatusMessage,
                'loadingTarget' => 'runIncrementalSyncAction',
                'loadingLabel' => __('seo-content-ai::filament.domain.sync_incremental_preparing'),
            ])
        </div>
    </div>

    {{-- Cập nhật thành phần bài --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="info"
                icon="heroicon-o-arrow-path-rounded-square"
                class="w-full justify-center"
                wire:click="runMetadataResyncAction"
                wire:confirm="{{ __('seo-content-ai::filament.domain.sync_metadata_confirm') }}"
                wire:loading.attr="disabled"
                wire:target="runMetadataResyncAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runMetadataResyncAction">
                    @if ($metadataSyncRunning)
                        {{ __('seo-content-ai::filament.domain.sync_metadata_running') }}
                    @elseif ($metadataSyncResumable)
                        {{ __('seo-content-ai::filament.domain.sync_metadata_resume') }}
                    @else
                        {{ __('seo-content-ai::filament.domain.sync_metadata') }}
                    @endif
                </span>
                <span wire:loading wire:target="runMetadataResyncAction">
                    {{ __('seo-content-ai::filament.domain.sync_metadata_preparing') }}
                </span>
            </x-filament::button>
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $metadataSyncStatus,
                'message' => $metadataSyncStatusMessage,
                'loadingTarget' => 'runMetadataResyncAction',
                'loadingLabel' => __('seo-content-ai::filament.domain.sync_metadata_preparing'),
            ])
        </div>
    </div>

    {{-- Cào lại keywords --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="danger"
                icon="heroicon-o-arrow-path"
                class="w-full justify-center"
                wire:click="runRescrapeKeywordsAction"
                wire:confirm="{{ __('seo-content-ai::filament.keyword.resync_linked_confirm') }}"
                wire:loading.attr="disabled"
                wire:target="runRescrapeKeywordsAction"
                :disabled="$syncDisabled"
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
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $keywordResyncStatus,
                'message' => $keywordResyncStatusMessage,
                'loadingTarget' => 'runRescrapeKeywordsAction',
                'loadingLabel' => __('seo-content-ai::filament.keyword.resync_linked_dispatching'),
            ])
        </div>
    </div>

    {{-- Kiểm tra link chết --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="warning"
                icon="heroicon-o-link-slash"
                class="w-full justify-center"
                wire:click="runAuditLinkStatusAction"
                wire:loading.attr="disabled"
                wire:target="runAuditLinkStatusAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runAuditLinkStatusAction">
                    {{ __('seo-content-ai::filament.domain.audit_link_status') }}
                </span>
                <span wire:loading wire:target="runAuditLinkStatusAction">
                    {{ __('seo-content-ai::filament.domain.audit_link_status_dispatching') }}
                </span>
            </x-filament::button>
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $auditLinkStatus,
                'message' => $auditLinkStatusMessage,
                'loadingTarget' => 'runAuditLinkStatusAction',
                'loadingLabel' => __('seo-content-ai::filament.domain.audit_link_status_dispatching'),
            ])
        </div>
    </div>

    {{-- Chấm điểm SEO --}}
    <div class="grid grid-cols-1 gap-2 border-t border-gray-200 py-3 dark:border-gray-700 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div class="flex flex-col gap-2 sm:flex-row">
            <x-filament::button
                type="button"
                color="primary"
                icon="heroicon-o-chart-bar"
                class="w-full justify-center"
                wire:click="runQueueMissingSeoScoringAction"
                wire:loading.attr="disabled"
                wire:target="runQueueMissingSeoScoringAction,runRetryFailedSeoScoringAction,runRequeueAllSeoScoringAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runQueueMissingSeoScoringAction">
                    {{ __('seo-content-ai::filament.domain.seo_scoring_queue_missing') }}
                </span>
                <span wire:loading wire:target="runQueueMissingSeoScoringAction">
                    {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                </span>
            </x-filament::button>
            @if (($this->getSeoScoringProgress()['failed'] ?? 0) > 0)
                <x-filament::button
                    type="button"
                    color="warning"
                    icon="heroicon-o-arrow-path"
                    class="w-full justify-center"
                    wire:click="runRetryFailedSeoScoringAction"
                    wire:loading.attr="disabled"
                    wire:target="runRetryFailedSeoScoringAction"
                    :disabled="$syncDisabled"
                >
                    <span wire:loading.remove wire:target="runRetryFailedSeoScoringAction">
                        {{ __('seo-content-ai::filament.domain.seo_scoring_retry_failed') }}
                    </span>
                    <span wire:loading wire:target="runRetryFailedSeoScoringAction">
                        {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                    </span>
                </x-filament::button>
            @endif
        </div>
        <div class="flex flex-col gap-2 sm:items-end">
            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-o-arrow-path-rounded-square"
                class="w-full justify-center sm:w-auto"
                wire:click="runRequeueAllSeoScoringAction"
                wire:confirm="{{ __('seo-content-ai::filament.domain.seo_scoring_requeue_all_confirm') }}"
                wire:loading.attr="disabled"
                wire:target="runRequeueAllSeoScoringAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runRequeueAllSeoScoringAction">
                    {{ __('seo-content-ai::filament.domain.seo_scoring_requeue_all') }}
                </span>
                <span wire:loading wire:target="runRequeueAllSeoScoringAction">
                    {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                </span>
            </x-filament::button>
        </div>
    </div>

    @if ($showTest)
        <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
            <div>
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-bug-ant"
                    class="w-full justify-center"
                    wire:click="mountAction('test_sync_data')"
                    wire:loading.attr="disabled"
                    wire:target="runIncrementalSyncAction, runMetadataResyncAction, mountAction('test_sync_data')"
                    :disabled="$incrementalSyncRunning || $metadataSyncRunning"
                >
                    {{ __('seo-content-ai::filament.domain.test_sync_debug') }}
                </x-filament::button>
            </div>
            <div class="min-h-[2.75rem] flex items-center sm:justify-end text-sm text-gray-500 dark:text-gray-400 sm:text-right">
                {{ __('seo-content-ai::filament.domain.sync_action_status_ready') }}
            </div>
        </div>
    @endif
</div>
