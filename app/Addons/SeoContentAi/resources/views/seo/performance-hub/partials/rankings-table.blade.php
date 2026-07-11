<section class="performance-hub-panel">
    <div class="performance-hub-panel__head performance-hub-panel__head--toolbar">
        <h2>{{ __('seo-content-ai::filament.performance_hub.tab_rankings') }}</h2>
        <input
            type="search"
            wire:model.live.debounce.400ms="keywordSearch"
            placeholder="{{ __('seo-content-ai::filament.performance_hub.filter_keyword') }}"
            class="performance-hub-input"
        />
    </div>

    <div class="performance-hub-table-wrap">
        <table class="performance-hub-table">
            <thead>
                <tr>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_keyword') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_position') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_change') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_url') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_status') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_updated') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="ranking-{{ $row['keyword_id'] ?? md5((string) ($row['keyword'] ?? '')) }}">
                        <td>{{ $row['keyword'] ?? '' }}</td>
                        <td>
                            @if (($row['position'] ?? null) !== null)
                                {{ number_format((float) $row['position'], 1) }}
                            @else
                                <span class="performance-hub-empty-value">{{ __('seo-content-ai::filament.performance_hub.empty_no_data') }}</span>
                            @endif
                        </td>
                        <td>
                            @if (($row['change'] ?? null) !== null)
                                <span @class([
                                    'performance-hub-change',
                                    'is-up' => ($row['change'] ?? 0) > 0,
                                    'is-down' => ($row['change'] ?? 0) < 0,
                                ])>
                                    {{ ($row['change'] ?? 0) > 0 ? '+' : '' }}{{ (int) $row['change'] }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="max-w-xs truncate">
                            @if (! empty($row['url']))
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer" class="text-emerald-600 hover:underline">{{ $row['url'] }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if (! empty($row['error']))
                                <span class="performance-hub-status-badge is-error" title="{{ $row['error'] }}">{{ $row['status'] ?? 'error' }}</span>
                            @elseif (! empty($row['status']))
                                <span class="performance-hub-status-badge">{{ $row['status'] }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $row['updated_at'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="performance-hub-table-empty">
                            {{ __('seo-content-ai::filament.performance_hub.rankings_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
