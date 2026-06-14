@php
    /** @var \App\Addons\SeoContentAi\Models\Keyword $record */
    use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;

    $record = $getRecord();
    $childCount = (int) ($record->children_count ?? 0);
    $childrenUrl = $childCount > 0
        ? KeywordResource::buildChildrenFilterUrl((int) $record->id)
        : null;
@endphp

<div class="min-w-0 max-w-[400px]">
    <div class="break-words font-bold text-gray-950 dark:text-white">
        {{ $record->phrase }}
    </div>

    @if ($childrenUrl !== null)
        <div class="mt-1 flex flex-wrap items-center gap-1.5">
            <a
                href="{{ $childrenUrl }}"
                wire:navigate
                class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/15 transition hover:bg-primary-100 hover:ring-primary-600/30 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/25 dark:hover:bg-primary-400/20"
                title="{{ __('seo-content-ai::filament.keyword.filter_children') }}"
            >
                <x-filament::icon icon="heroicon-m-tag" class="h-3.5 w-3.5 shrink-0" />
                <span>{{ __('seo-content-ai::filament.keyword.child_count', ['count' => $childCount]) }}</span>
            </a>
        </div>
    @endif
</div>
