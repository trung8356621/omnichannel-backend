@php
    $hasData = (bool) ($has_data ?? false);
    $avgScore = $avg_score ?? null;
    $segments = is_array($segments ?? null) ? $segments : [];
    $donutGradient = (string) ($donut_gradient ?? '');
    $overviewCss = base_path('app/Addons/SeoContentAi/resources/css/domain-overview.css');
@endphp

<x-filament-widgets::widget>
    @if(is_readable($overviewCss))
        <style>{!! file_get_contents($overviewCss) !!}</style>
    @endif

    <x-filament::section
        :heading="__('seo-content-ai::filament.dashboard.score_chart_heading')"
        :description="__('seo-content-ai::filament.dashboard.score_chart_description', ['count' => $scored ?? 0])"
        icon="heroicon-o-chart-pie"
    >
        @if(! $hasData)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.dashboard.score_chart_empty') }}
            </p>
        @else
            <div class="seo-score-donut">
                <div class="seo-score-donut__chart" style="background: {{ $donutGradient }};">
                    <div class="seo-score-donut__hole">
                        <span class="seo-score-donut__avg">{{ $avgScore !== null ? number_format((float) $avgScore, 1) : '—' }}</span>
                        <span class="seo-score-donut__label">{{ __('seo-content-ai::filament.dashboard.score_avg_label') }}</span>
                    </div>
                </div>

                <ul class="seo-score-donut__legend">
                    @foreach ($segments as $segment)
                        <li class="seo-score-donut__legend-item" wire:key="score-seg-{{ $segment['key'] ?? $loop->index }}">
                            <span class="seo-score-donut__swatch" style="background: {{ $segment['color'] ?? '#94a3b8' }};"></span>
                            <span class="seo-score-donut__legend-label">{{ $segment['label'] ?? '' }}</span>
                            <span class="seo-score-donut__legend-count">{{ $segment['count'] ?? 0 }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
