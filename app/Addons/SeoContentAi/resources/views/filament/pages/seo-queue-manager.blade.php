<div wire:poll.5s="refreshQueueStatus">
    <x-filament-panels::page>
        @php
            $paused = (bool) ($queueStatus['paused'] ?? false);
            $workerStatus = (string) ($queueStatus['worker_status'] ?? 'idle');
            $readOnly = $this->isPanelReadOnly();
            $workerBadgeColor = match ($workerStatus) {
                'running' => 'success',
                'offline' => 'danger',
                default => 'gray',
            };
        @endphp

        <div class="space-y-6">
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-1">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ __('seo-content-ai::filament.queue_manager.status_heading') }}
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.queue_manager.status_description') }}
                        </p>
                    </div>

                    <x-filament::button
                        type="button"
                        color="gray"
                        icon="heroicon-o-arrow-path"
                        wire:click="refreshQueueStatus"
                        wire:loading.attr="disabled"
                        wire:target="refreshQueueStatus"
                    >
                        {{ __('seo-content-ai::filament.queue_manager.refresh') }}
                    </x-filament::button>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.queue_manager.worker_status') }}
                        </p>
                        <div class="mt-2">
                            <x-filament::badge :color="$workerBadgeColor">
                                {{ __('seo-content-ai::filament.queue_manager.worker_status_'.$workerStatus) }}
                            </x-filament::badge>
                        </div>
                        @if (filled($queueStatus['last_reserved_at'] ?? null))
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.queue_manager.last_activity') }}:
                                {{ $queueStatus['last_reserved_at'] }}
                            </p>
                        @endif
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.queue_manager.audit_pause') }}
                        </p>
                        <div class="mt-2">
                            <x-filament::badge :color="$paused ? 'warning' : 'success'">
                                {{ $paused
                                    ? __('seo-content-ai::filament.queue_manager.paused_label')
                                    : __('seo-content-ai::filament.queue_manager.active_label') }}
                            </x-filament::badge>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.queue_manager.pending_audit_jobs') }}
                        </p>
                        <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">
                            {{ (int) ($queueStatus['pending_audit_jobs'] ?? 0) }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.queue_manager.running_audit_jobs') }}
                        </p>
                        <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">
                            {{ (int) ($queueStatus['running_audit_jobs'] ?? 0) }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ __('seo-content-ai::filament.queue_manager.actions_heading') }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.queue_manager.actions_description') }}
                </p>

                <div class="mt-5 flex flex-wrap gap-3">
                    @if (! $readOnly && ! $paused)
                        <x-filament::button
                            type="button"
                            color="warning"
                            icon="heroicon-o-pause"
                            wire:click="pauseQueue"
                            wire:loading.attr="disabled"
                            wire:target="pauseQueue"
                        >
                            {{ __('seo-content-ai::filament.queue_manager.pause_action') }}
                        </x-filament::button>
                    @endif

                    @if (! $readOnly && $paused)
                        <x-filament::button
                            type="button"
                            color="success"
                            icon="heroicon-o-play"
                            wire:click="resumeQueue"
                            wire:loading.attr="disabled"
                            wire:target="resumeQueue"
                        >
                            {{ __('seo-content-ai::filament.queue_manager.resume_action') }}
                        </x-filament::button>
                    @endif

                    @if (! $readOnly)
                        <x-filament::button
                            type="button"
                            color="danger"
                            icon="heroicon-o-stop"
                            wire:click="stopAuditJobs"
                            wire:loading.attr="disabled"
                            wire:target="stopAuditJobs"
                        >
                            {{ __('seo-content-ai::filament.queue_manager.stop_action') }}
                        </x-filament::button>
                    @endif
                </div>

                <div class="mt-6 rounded-lg bg-gray-50 p-4 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <p>{{ __('seo-content-ai::filament.queue_manager.worker_hint') }}</p>
                    <p class="mt-2">{{ __('seo-content-ai::filament.queue_manager.audit_hint') }}</p>
                </div>
            </section>
        </div>
    </x-filament-panels::page>
</div>
