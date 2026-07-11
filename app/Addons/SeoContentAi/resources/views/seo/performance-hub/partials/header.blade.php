@php
    use App\Addons\SeoContentAi\Support\SerpProviderKeys;

    $isRankProvider = SerpProviderKeys::isValid($dataSource);
@endphp

<header class="performance-hub-header">
    <div class="performance-hub-header__main">
        <div>
            <h1 class="performance-hub-title">{{ __('seo-content-ai::filament.performance_hub.title') }}</h1>
            <p class="performance-hub-subtitle">{{ __('seo-content-ai::filament.performance_hub.subtitle') }}</p>
        </div>
        <div class="performance-hub-toolbar">
            @if ($isRankProvider)
                <div class="performance-hub-toolbar__field">
                    <label for="perf-location">{{ __('seo-content-ai::filament.performance_hub.filter_location') }}</label>
                    <input id="perf-location" type="text" wire:model.live.debounce.400ms="location" placeholder="{{ __('seo-content-ai::filament.performance_hub.filter_location_placeholder') }}" class="performance-hub-input" />
                </div>
                <div class="performance-hub-toolbar__field">
                    <label for="perf-device">{{ __('seo-content-ai::filament.performance_hub.filter_device') }}</label>
                    <x-select id="perf-device" wire:model.live="device" class="performance-hub-select">
                        <option value="all">{{ __('seo-content-ai::filament.performance_hub.device_all') }}</option>
                        <option value="desktop">{{ __('seo-content-ai::filament.performance_hub.device_desktop') }}</option>
                        <option value="mobile">{{ __('seo-content-ai::filament.performance_hub.device_mobile') }}</option>
                        <option value="tablet">{{ __('seo-content-ai::filament.performance_hub.device_tablet') }}</option>
                    </x-select>
                </div>
                <div class="performance-hub-toolbar__field">
                    <label for="perf-keyword">{{ __('seo-content-ai::filament.performance_hub.filter_keyword') }}</label>
                    <input id="perf-keyword" type="text" wire:model.live.debounce.400ms="keywordSearch" placeholder="{{ __('seo-content-ai::filament.performance_hub.filter_keyword') }}" class="performance-hub-input" />
                </div>
                <button
                    type="button"
                    wire:click="runKeywordRankCheck"
                    wire:loading.attr="disabled"
                    wire:target="runKeywordRankCheck"
                    class="performance-hub-action-btn"
                >
                    <span wire:loading.remove wire:target="runKeywordRankCheck">{{ __('seo-content-ai::filament.performance_hub.run_rank_check') }}</span>
                    <span wire:loading wire:target="runKeywordRankCheck">{{ __('seo-content-ai::filament.performance_hub.running_rank_check') }}</span>
                </button>
            @else
                <p class="performance-hub-toolbar__note">{{ __('seo-content-ai::filament.performance_hub.gsc_date_range_note') }}</p>
            @endif
        </div>
    </div>
</header>
