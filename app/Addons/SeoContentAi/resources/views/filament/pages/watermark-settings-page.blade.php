<x-filament-panels::page>
    <div class="space-y-6">
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
            <div class="flex flex-wrap items-center gap-3">
                @unless ($this->hasLockedGlobalSite())
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300" for="wm-auto-site">
                        Domain:
                    </label>
                    <x-select
                        id="wm-auto-site"
                        wire:model.live="siteId"
                        class="text-sm rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    >
                        <option value="">-- Select domain --</option>
                        @foreach ($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->domain }}</option>
                        @endforeach
                    </x-select>
                @else
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Domain:</span>
                    <span class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white px-3 py-2">
                        {{ $this->currentSiteDomain() ?? ('Site #' . (int) ($siteId ?? 0)) }}
                    </span>
                @endunless
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Use "Watermark designer" to edit the drag-and-drop canvas; this page manages automatic upload rules and batch processing.
            </p>
            @if ($siteId)
                <div class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-3">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Batch apply</p>
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.live="batchApplyWatermark"
                            class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        />
                        <span>
                            <strong>Watermark</strong> — apply copyright mark from saved design
                        </span>
                    </label>
                    <p class="text-xs text-gray-500 pl-6">
                        Always <strong>optimize images</strong> (resize, WebP conversion from "Image optimization settings").
                        @if (! $batchApplyWatermark)
                            Only optimize files that are <strong>not .webp</strong>.
                        @else
                            Optimize after watermarking (.webp files are only watermarked, not reconverted).
                        @endif
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button
                            type="button"
                            color="warning"
                            icon="heroicon-o-photo"
                            wire:click="applyBatchToCurrentSite"
                            wire:confirm="Process all local and WordPress images for this domain? This may take a few minutes."
                        >
                            Apply to all images
                        </x-filament::button>
                        <a
                            href="{{ \App\Addons\SeoContentAi\Filament\Pages\ImageOptimizationSettings::getUrl(['siteId' => $siteId]) }}"
                            class="text-xs text-primary-600 hover:underline"
                        >
                            Configure WebP optimization
                        </a>
                    </div>
                </div>
            @endif
        </div>

        <form wire:submit.prevent="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-seo-content-ai::form-save-button
                    target="save"
                    :label="__('Save settings')"
                />
            </div>
        </form>
    </div>
</x-filament-panels::page>
