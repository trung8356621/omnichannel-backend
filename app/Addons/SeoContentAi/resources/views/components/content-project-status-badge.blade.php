@props([
    'badge' => null,
])

@php
    /** @var array{key?: string, label?: string, classes?: string, icon?: string}|null $badge */
    $badge = is_array($badge) ? $badge : [];
    $label = (string) ($badge['label'] ?? '—');
    $classes = (string) ($badge['classes'] ?? 'inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-400/30');
    $icon = (string) ($badge['icon'] ?? '');
@endphp

<span {{ $attributes->class([$classes]) }} title="{{ $label }}">
    @if ($icon !== '')
        <x-filament::icon :icon="$icon" class="h-3.5 w-3.5 shrink-0 opacity-90" />
    @endif
    <span>{{ $label }}</span>
</span>
