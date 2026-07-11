@php
    $connection = $connections['provider'] ?? [];
    $settingsUrl = $connections['settings_url'] ?? '#';
@endphp

<section class="performance-hub-connection-strip">
    <div class="performance-hub-connection-card">
        <span class="performance-hub-connection-card__label">{{ $connection['label'] ?? '' }}</span>
        <span class="performance-hub-connection-card__status">{{ $connection['status'] ?? __('seo-content-ai::filament.api_connections.not_configured') }}</span>
        @if (! empty($connection['last_checked_at']))
            <span class="performance-hub-connection-card__meta">{{ __('seo-content-ai::filament.performance_hub.last_tested') }}: {{ $connection['last_checked_at'] }}</span>
        @endif
        @if (! empty($connection['last_rank_check_at']))
            <span class="performance-hub-connection-card__meta">{{ __('seo-content-ai::filament.performance_hub.last_rank_check') }}: {{ $connection['last_rank_check_at'] }}</span>
        @endif
        @if (! empty($connection['usage_label']))
            <span class="performance-hub-connection-card__meta">{{ $connection['usage_label'] }}</span>
        @endif
    </div>

    <button
        type="button"
        wire:click="testSerpConnection"
        wire:loading.attr="disabled"
        wire:target="testSerpConnection"
        class="performance-hub-connection-card performance-hub-connection-card--action"
    >
        <span wire:loading.remove wire:target="testSerpConnection">{{ __('seo-content-ai::filament.api_connections.test_connection') }}</span>
        <span wire:loading wire:target="testSerpConnection">{{ __('seo-content-ai::filament.api_connections.testing_connection') }}</span>
    </button>

    <a href="{{ $settingsUrl }}" class="performance-hub-connection-card performance-hub-connection-card--link">
        {{ __('seo-content-ai::filament.performance_hub.manage_api_settings') }}
    </a>
</section>
