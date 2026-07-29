@props([
    'card' => 'total',
    'label' => '',
    'value' => 0,
    'active' => false,
])

@php
    $accent = \App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStatusBadgePresenter::summaryAccent((string) $card);
@endphp

<button
    type="button"
    {{ $attributes->class([
        'cp-ops-kpi-card',
        'is-active' => $active,
        'accent-'.$accent['key'],
    ]) }}
    aria-pressed="{{ $active ? 'true' : 'false' }}"
    aria-label="{{ $label }}: {{ (int) $value }}"
>
    <span class="cp-ops-kpi-card__top">
        <span class="cp-ops-kpi-card__label">{{ $label }}</span>
        <x-filament::icon :icon="$accent['icon']" class="cp-ops-kpi-card__icon" />
    </span>
    <span class="cp-ops-kpi-card__value">{{ (int) $value }}</span>
</button>
