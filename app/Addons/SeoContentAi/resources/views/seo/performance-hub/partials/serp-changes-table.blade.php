<section class="performance-hub-panel">
    <div class="performance-hub-table-wrap">
        <table class="performance-hub-table">
            <thead>
                <tr>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_keyword') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_position') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_change') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_volume') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_allintitle') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_updated') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="serp-change-{{ md5((string) ($row['keyword'] ?? '')) }}">
                        <td>{{ $row['keyword'] ?? '' }}</td>
                        <td>{{ ($row['position'] ?? null) !== null ? number_format((float) $row['position'], 1) : '—' }}</td>
                        <td>
                            <span @class([
                                'performance-hub-change',
                                'is-up' => ($row['change'] ?? 0) > 0,
                                'is-down' => ($row['change'] ?? 0) < 0,
                            ])>
                                {{ ($row['change'] ?? 0) > 0 ? '+' : '' }}{{ (int) ($row['change'] ?? 0) }}
                            </span>
                        </td>
                        <td>{{ ($row['volume'] ?? null) !== null ? number_format((int) $row['volume']) : '—' }}</td>
                        <td>{{ ($row['allintitle'] ?? null) !== null ? number_format((int) $row['allintitle']) : '—' }}</td>
                        <td>{{ $row['updated_at'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="performance-hub-table-empty">
                            {{ __('seo-content-ai::filament.performance_hub.serp_changes_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
