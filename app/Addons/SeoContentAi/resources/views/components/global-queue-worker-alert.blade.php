@if ($showAlert ?? false)
    <div
        role="alert"
        class="seo-global-queue-alert mb-5 overflow-hidden rounded-xl border border-danger-300/80 border-l-4 border-l-danger-500 bg-danger-50 shadow-sm dark:border-danger-500/30 dark:bg-danger-950/25"
    >
        <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:p-5">
            <div class="flex min-w-0 items-start gap-3.5">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300">
                    <x-filament::icon
                        icon="heroicon-o-exclamation-triangle"
                        class="h-5 w-5"
                    />
                </div>

                <div class="min-w-0 space-y-1">
                    <p class="text-sm font-semibold text-danger-900 dark:text-danger-100">
                        {{ __('seo-content-ai::filament.global_queue_alert.title') }}
                    </p>
                    <p class="text-sm leading-relaxed text-danger-700 dark:text-danger-300/90">
                        {{ __('seo-content-ai::filament.global_queue_alert.body', [
                            'pending' => (int) ($queueStatus['pending_work_total'] ?? 0),
                        ]) }}
                    </p>
                </div>
            </div>

            <x-filament::button
                tag="a"
                :href="$queueManagerUrl"
                color="danger"
                size="sm"
                icon="heroicon-m-arrow-right"
                icon-position="after"
                class="shrink-0 self-start sm:self-center"
            >
                {{ __('seo-content-ai::filament.global_queue_alert.action') }}
            </x-filament::button>
        </div>
    </div>
@endif
