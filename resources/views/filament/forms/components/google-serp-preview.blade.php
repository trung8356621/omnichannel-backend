@php
    /** @var \App\Forms\Components\GoogleSerpPreview $field */
    $preview = $field->getParsedPreview(fn (string $path): mixed => $get($path));
    $type = (string) ($preview['type'] ?? 'unknown');
    $title = (string) ($preview['title'] ?? '');
    $description = (string) ($preview['description'] ?? '');
    $displayUrl = (string) ($preview['display_url'] ?? 'www.example.com');
    $meta = is_array($preview['meta'] ?? null) ? $preview['meta'] : [];
    $ratingValue = isset($meta['rating_value']) ? (float) $meta['rating_value'] : null;
    $reviewCount = isset($meta['review_count']) ? (int) $meta['review_count'] : null;
    $priceDisplay = (string) ($meta['price'] ?? '');
    $availabilityLabel = (string) ($meta['availability_label'] ?? '');
    $isProduct = $type === 'product';
    $hasContent = $title !== '' || $description !== '' || $displayUrl !== 'www.example.com';
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
    :has-inline-label="$hasInlineLabel()"
    :id="$getId()"
    :label="$getLabel()"
    :label-sr-only="$isLabelHidden()"
    :helper-text="$getHelperText()"
    :hint="$getHint()"
    :hint-actions="$getHintActions()"
    :hint-color="$getHintColor()"
    :hint-icon="$getHintIcon()"
    :hint-icon-tooltip="$getHintIconTooltip()"
    :state-path="$getStatePath()"
>
    <div
        x-data="{ device: 'desktop' }"
        {{
            $attributes
                ->merge($getExtraAttributes(), escape: false)
                ->class(['fi-google-serp-preview space-y-3'])
        }}
    >
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                Google Search preview
            </p>
            <div
                class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-gray-800"
                role="tablist"
                aria-label="Preview device"
            >
                <button
                    type="button"
                    role="tab"
                    :aria-selected="device === 'desktop'"
                    @click="device = 'desktop'"
                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition"
                    :class="device === 'desktop'
                        ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-gray-100'
                        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                >
                    <x-filament::icon icon="heroicon-o-computer-desktop" class="h-4 w-4" />
                    <span class="sr-only sm:not-sr-only">Desktop</span>
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="device === 'mobile'"
                    @click="device = 'mobile'"
                    class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition"
                    :class="device === 'mobile'
                        ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-gray-100'
                        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                >
                    <x-filament::icon icon="heroicon-o-device-phone-mobile" class="h-4 w-4" />
                    <span class="sr-only sm:not-sr-only">Mobile</span>
                </button>
            </div>
        </div>

        <div
            class="mx-auto w-full rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-[max-width] duration-200 dark:border-gray-700 dark:bg-white"
            :class="device === 'mobile' ? 'max-w-[375px]' : 'max-w-[600px]'"
        >
            @if (! $hasContent)
                <p class="text-sm text-gray-500 dark:text-gray-500">
                    Paste Rank Math JSON-LD (with <code class="text-xs">@@graph</code>) or bind SEO fields to see a preview.
                </p>
            @else
                <div class="space-y-0.5">
                    <p class="truncate text-sm leading-snug text-[#006621]">
                        {{ $displayUrl }}
                    </p>

                    <h3 class="line-clamp-1 text-xl leading-snug text-[#1a0dab] hover:underline">
                        {{ $title !== '' ? $title : 'Page title preview' }}
                    </h3>

                    @if ($isProduct && ($ratingValue !== null || $priceDisplay !== '' || $availabilityLabel !== ''))
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-[#545454]">
                            @if ($ratingValue !== null)
                                <span class="inline-flex items-center gap-0.5" aria-hidden="true">
                                    @for ($star = 1; $star <= 5; $star++)
                                        @php
                                            $filled = $ratingValue >= $star - 0.25;
                                            $half = ! $filled && $ratingValue >= $star - 0.75;
                                        @endphp
                                        <svg
                                            class="h-3.5 w-3.5 {{ $filled || $half ? 'text-[#fbbc04]' : 'text-gray-300' }}"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                        </svg>
                                    @endfor
                                </span>
                                @if ($reviewCount !== null && $reviewCount > 0)
                                    <span>{{ number_format($reviewCount) }} reviews</span>
                                @endif
                            @endif

                            @if ($priceDisplay !== '')
                                <span class="font-medium text-[#202124]">{{ $priceDisplay }}</span>
                            @endif

                            @if ($availabilityLabel !== '')
                                <span>· {{ $availabilityLabel }}</span>
                            @endif
                        </div>
                    @endif

                    <p class="line-clamp-2 text-sm leading-snug text-[#545454]">
                        {{ $description !== '' ? $description : 'Meta description preview will appear here.' }}
                    </p>
                </div>
            @endif
        </div>

        @if ($isProduct)
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Product rich result: rating and price are read from JSON-LD <code class="text-[11px]">offers</code> / <code class="text-[11px]">aggregateRating</code>.
            </p>
        @endif
    </div>
</x-dynamic-component>
