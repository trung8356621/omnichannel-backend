@php
    /** @var \App\Addons\SeoContentAi\Models\Keyword $record */
    use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;

    $record = $getRecord();
    $groups = KeywordResource::resolveLinkDestinationGroups($record);

    $mainHeading = __('seo-content-ai::filament.keyword.destinations_dropdown_main_heading');
    $internalHeading = __('seo-content-ai::filament.keyword.destinations_dropdown_internal_heading');
    $sourceCaption = __('seo-content-ai::filament.keyword.destinations_action_view_source');
    $targetCaption = __('seo-content-ai::filament.keyword.destinations_action_view_target');
@endphp

@if ($groups === [])
    <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
@else
    <div x-data="{ openDomain: null }" x-on:keydown.escape.window="openDomain = null" class="w-full max-w-full">
        <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($groups as $group)
                @php
                    $domain = (string) ($group['domain'] ?? '');
                    $siteId = (string) ((int) ($group['site_id'] ?? 0));
                    $badge = is_array($group['badge'] ?? null) ? $group['badge'] : [];
                    $variant = (string) ($badge['variant'] ?? 'success');
                    $icon = (string) ($badge['icon'] ?? 'heroicon-m-bookmark-square');
                    $emoji = (string) ($badge['emoji'] ?? '🎯');
                    $isSuccess = $variant === 'success';
                    $triggerClass = $isSuccess
                        ? 'bg-emerald-50 text-emerald-800 ring-emerald-500/20 hover:bg-emerald-100/80 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-400/25'
                        : 'bg-slate-100 text-slate-700 ring-slate-300/60 hover:bg-slate-200/70 dark:bg-white/5 dark:text-slate-200 dark:ring-white/10';
                @endphp

                <button
                    type="button"
                    x-on:click.stop="openDomain = openDomain === '{{ $siteId }}' ? null : '{{ $siteId }}'"
                    x-bind:aria-expanded="openDomain === '{{ $siteId }}'"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset shadow-sm transition-all duration-200 {{ $triggerClass }}"
                    x-bind:class="openDomain === '{{ $siteId }}' ? 'ring-2 ring-blue-400/40 shadow-md' : ''"
                >
                    <x-filament::icon :icon="$icon" class="h-3.5 w-3.5 shrink-0 opacity-90" />
                    <span>{{ $domain }}</span>
                    <x-filament::icon
                        icon="heroicon-m-chevron-down"
                        class="h-3.5 w-3.5 shrink-0 opacity-50 transition-transform duration-200"
                        x-bind:class="openDomain === '{{ $siteId }}' ? 'rotate-180 opacity-80' : ''"
                    />
                </button>
            @endforeach
        </div>

        @foreach ($groups as $group)
            @php
                $domain = (string) ($group['domain'] ?? '');
                $siteId = (string) ((int) ($group['site_id'] ?? 0));
                $mainLinks = is_array($group['main_links'] ?? null) ? $group['main_links'] : [];
                $internalLinks = is_array($group['internal_links'] ?? null) ? $group['internal_links'] : [];
            @endphp

            <div x-cloak x-show="openDomain === '{{ $siteId }}'" x-collapse class="w-full max-w-full">
                <div class="mb-3 mt-2 block w-full space-y-3 rounded-xl border border-slate-200/60 bg-slate-50/60 p-4 shadow-inner dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-center gap-2 border-b border-slate-200/50 pb-2 dark:border-white/10">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-white text-[11px] shadow-sm ring-1 ring-slate-200/60 dark:bg-gray-900 dark:ring-white/10">🌐</span>
                        <span class="text-xs font-semibold tracking-tight text-slate-800 dark:text-slate-100">{{ $domain }}</span>
                    </div>

                    @if ($mainLinks !== [])
                        <div class="space-y-2">
                            @foreach ($mainLinks as $mainLink)
                                @php
                                    $mainHref = (string) ($mainLink['edit_url'] ?? $mainLink['url'] ?? '');
                                    $mainIsEdit = (bool) ($mainLink['is_edit_link'] ?? false);
                                @endphp
                                @if ($mainHref !== '')
                                    <div class="flex w-full items-center justify-between rounded-lg border border-slate-200/80 border-l-4 border-l-emerald-500 bg-white p-3 shadow-sm transition-all duration-200 hover:shadow-md dark:border-white/10 dark:bg-gray-900/50 dark:border-l-emerald-400">
                                        <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">
                                            🎯 {{ $mainHeading }}
                                        </span>
                                        <a
                                            href="{{ $mainHref }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            class="ml-3 min-w-0 text-right text-xs font-semibold text-slate-800 transition-colors hover:text-blue-600 hover:underline dark:text-slate-100 dark:hover:text-blue-400"
                                        >
                                        <svg class="fi-icon-btn-icon h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
  <path d="m5.433 13.917 1.262-3.155A4 4 0 0 1 7.58 9.42l6.92-6.918a2.121 2.121 0 0 1 3 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 0 1-.65-.65Z"></path>
  <path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0 0 10 3H4.75A2.75 2.75 0 0 0 2 5.75v9.5A2.75 2.75 0 0 0 4.75 18h9.5A2.75 2.75 0 0 0 17 15.25V10a.75.75 0 0 0-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5Z"></path>
</svg>
                                        </a>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if ($internalLinks !== [])
                        <div @class(['space-y-1', 'pt-1' => $mainLinks !== []])>
                            <div class="mb-2 flex items-center space-x-1 text-[10px] font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">
                                <x-filament::icon icon="heroicon-m-link" class="h-3.5 w-3.5 shrink-0" />
                                <span>{{ $internalHeading }}</span>
                            </div>

                            @foreach ($internalLinks as $internalLink)
                                @php
                                    $sourceHref = (string) ($internalLink['source_edit_url'] ?? $internalLink['source_url'] ?? '');
                                    $destinationUrl = (string) ($internalLink['destination_url'] ?? $internalLink['url'] ?? '');
                                    $sourceIsEdit = (bool) ($internalLink['source_is_edit_link'] ?? false);
                                @endphp

                                <div class="my-2 flex w-full items-center justify-between rounded-lg border border-slate-200/60 bg-white p-3 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-blue-300/70 hover:shadow-sm dark:border-white/10 dark:bg-gray-900/50 dark:hover:border-blue-400/40">
                                    <div class="flex w-[45%] items-center space-x-2 text-left">
                                        @if ($sourceHref !== '')
                                            <a
                                                href="{{ $sourceHref }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                class="truncate text-xs font-medium text-slate-700 transition-colors hover:text-blue-600 dark:text-slate-200 dark:hover:text-blue-400"
                                                title="{{ $sourceCaption }}"
                                            >
                                                📄 {{ $sourceCaption }}
                                            </a>
                                        @else
                                            <span class="truncate text-xs font-medium text-slate-400">📄 {{ $sourceCaption }}</span>
                                        @endif
                                    </div>

                                    <div class="flex w-[10%] items-center justify-center px-2">
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-400 shadow-sm dark:bg-white/10 dark:text-slate-500">
                                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-3.5 w-3.5" />
                                        </span>
                                    </div>

                                    <div class="flex w-[45%] items-center justify-end space-x-2 text-right">
                                        @if ($destinationUrl !== '')
                                            <a
                                                href="{{ $destinationUrl }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="truncate text-xs font-medium text-blue-600 transition-colors hover:underline dark:text-blue-400"
                                                title="{{ $targetCaption }}"
                                            >
                                                🎯 {{ $targetCaption }}
                                            </a>
                                        @else
                                            <span class="truncate text-xs font-medium text-slate-400">🎯 {{ $targetCaption }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
