@php
    /** @var \App\Models\Site $record */
    $record = $getRecord();
    $siteUrl = ($record->ssl ? 'https://' : 'http://') . $record->domain;
    $faviconUrl = 'https://www.google.com/s2/favicons?domain=' . rawurlencode($record->domain) . '&sz=128';
    $settingsSs = $record->primarySiteServiceForSettings();
    $settingsUrl = $settingsSs
        ? \App\Filament\Resources\SiteServiceResource::getUrl('edit', ['record' => $settingsSs])
        : null;
    $statusStyles = [
        'active' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/30',
        'inactive' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/30',
        'maintenance' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/30',
    ];
    $statusClass = $statusStyles[$record->status] ?? 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-300 dark:ring-gray-500/30';
@endphp

<div
    class="relative rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    @if ($settingsUrl)
        <a
            href="{{ $settingsUrl }}"
            wire:navigate
            class="absolute end-3 top-3 inline-flex rounded-lg p-1.5 text-gray-500 ring-1 ring-transparent transition hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus-visible:ring-primary-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100"
            title="{{ __('Service settings') }}"
        >
            <x-filament::icon icon="heroicon-o-cog-8-tooth" class="h-5 w-5" />
        </a>
    @endif

    <div class="flex gap-4 pe-8">
        <div class="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50 ring-1 ring-gray-100 dark:bg-gray-800 dark:ring-gray-700">
            <img
                src="{{ $faviconUrl }}"
                alt=""
                class="h-9 w-9 object-contain"
                loading="lazy"
            />
            @if ($record->ssl)
                <span
                    class="absolute bottom-0 end-0 rounded-tl-md bg-white/90 px-0.5 text-[9px] font-semibold text-green-600 shadow-sm dark:bg-gray-900/90 dark:text-green-400"
                    title="{{ __('SSL enabled') }}"
                >
                    TLS
                </span>
            @endif
        </div>

        <div class="min-w-0 flex-1 space-y-2">
            <p class="truncate text-base font-bold text-gray-900 dark:text-white">
                {{ $record->domain }}
            </p>
            <a
                href="{{ $siteUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="block truncate text-sm text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ $siteUrl }}
            </a>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $statusClass }}">
                    {{ __($record->status) }}
                </span>
                @if ($record->relationLoaded('user') && $record->user)
                    <span class="truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Owner') }}: {{ $record->user->name }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
