@props([
    'disabled' => false,
    'size' => 'default',
    'options' => [],
    'selected' => null,
])

@php
    $wrapClass = match ($size) {
        'sm' => 'x-select-wrap x-select-wrap--sm',
        'compact' => 'x-select-wrap x-select-wrap--compact',
        'inline' => 'x-select-wrap x-select-wrap--inline',
        default => 'x-select-wrap',
    };

    if (filled($attributes->get('wrapClass'))) {
        $wrapClass .= ' ' . $attributes->get('wrapClass');
    }
@endphp

<div class="{{ $wrapClass }}">
    <select
        {{ $disabled ? 'disabled' : '' }}
        {{ $attributes->except(['wrapClass'])->class('x-select classic-select') }}
    >
        {{ $slot }}
    </select>
    <span class="x-select-chevron" aria-hidden="true"></span>
</div>

@once
    <style>
        .x-select-wrap {
            position: relative;
            display: block;
            width: 100%;
            max-width: 100%;
        }

        .x-select-wrap--sm,
        .x-select-wrap--inline {
            width: auto;
            min-width: 9rem;
            max-width: 18rem;
        }

        .x-select-wrap--narrow {
            max-width: 16rem;
        }

        .x-select-wrap--compact {
            max-width: 100%;
        }

        .x-select {
            display: block;
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0.625rem 2.25rem 0.625rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            line-height: 1.4;
            color: #374151;
            background-color: #fff;
            background-image: none !important;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
            cursor: pointer;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
        }

        .x-select:hover:not(:disabled) {
            border-color: #d1d5db;
            box-shadow: 0 1px 3px rgb(15 23 42 / 0.08);
        }

        .x-select:focus {
            outline: none;
            border-color: #f43f5e;
            box-shadow: 0 0 0 3px rgb(244 63 94 / 0.14);
        }

        .x-select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .x-select-wrap--sm .x-select,
        .x-select-wrap--inline .x-select {
            padding: 0.375rem 2rem 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .x-select-wrap--compact .x-select {
            padding: 0.5rem 2rem 0.5rem 0.625rem;
            font-size: 0.8125rem;
            border-radius: 0.4375rem;
        }

        .x-select-chevron {
            pointer-events: none;
            position: absolute;
            top: 50%;
            right: 0.625rem;
            width: 1rem;
            height: 1rem;
            transform: translateY(-50%);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.75' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
        }

        .x-select-wrap--sm .x-select-chevron,
        .x-select-wrap--compact .x-select-chevron {
            right: 0.5rem;
            width: 0.875rem;
            height: 0.875rem;
        }

        .dark .x-select {
            background-color: rgb(15 23 42);
            border-color: rgb(51 65 85);
            color: rgb(226 232 240);
        }

        .dark .x-select:hover:not(:disabled) {
            border-color: rgb(71 85 105);
            background-color: rgb(15 23 42);
        }

        .dark .x-select:focus {
            border-color: #fb7185;
            box-shadow: 0 0 0 3px rgb(251 113 133 / 0.18);
        }

        .dark .x-select-chevron {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2394a3b8' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.75' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
        }

        .classic-select::-ms-expand {
            display: none;
        }
    </style>
@endonce
