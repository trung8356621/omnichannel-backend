<div class="flex w-full flex-wrap items-center justify-between gap-3">
    <span>{{ $title }}</span>
    <button
        type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-danger-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-danger-500 disabled:cursor-not-allowed disabled:opacity-60"
        x-data
        x-cloak
        x-show="$store.seoRunQueue.isRunning"
        @click="$store.seoRunQueue.requestStop()"
        :disabled="$store.seoRunQueue.stopRequested"
    >
        <span x-show="!$store.seoRunQueue.stopRequested">{{ __('seo-content-ai::filament.projects.run_stop') }}</span>
        <span x-show="$store.seoRunQueue.stopRequested">{{ __('seo-content-ai::filament.projects.run_stopping') }}</span>
    </button>
</div>
