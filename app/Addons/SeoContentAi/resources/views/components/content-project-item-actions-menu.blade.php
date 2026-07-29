@props([
    'row' => [],
])

@php
    $tid = (int) ($row['task_id'] ?? 0);
    $articleUrl = $row['article_edit_url'] ?? null;
    $a = \App\Addons\SeoContentAi\Support\ContentProject\ContentProjectItemActionsPresenter::forRow($row);
@endphp

<div
    {{ $attributes->class(['relative inline-flex items-center gap-1']) }}
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
>
    @if ($a['open_article'] && $articleUrl)
        <a
            href="{{ $articleUrl }}"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-primary-600 ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:ring-gray-700 dark:hover:bg-gray-800"
            aria-label="Open article"
            title="Open article"
        >
            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
        </a>
    @elseif ($a['generate'])
        <button
            type="button"
            wire:click="generateOne({{ $tid }})"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-success-600 ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-success-400 dark:ring-gray-700 dark:hover:bg-gray-800"
            aria-label="Generate"
            title="Generate"
        >
            <x-filament::icon icon="heroicon-o-play" class="h-4 w-4" />
        </button>
    @endif

    <div class="relative">
        <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:ring-gray-700 dark:hover:bg-gray-800"
            @click="open = !open"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-label="Item actions"
        >
            <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-4 w-4" />
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition
            @click.outside="open = false"
            role="menu"
            class="absolute right-0 z-30 mt-1 max-h-72 w-56 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
        >
            @if ($a['has_content'])
                <p class="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">Content</p>
                @if ($a['open_article'] && $articleUrl)
                    <a role="menuitem" href="{{ $articleUrl }}" class="block px-3 py-1.5 text-xs hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Open article</a>
                @endif
                @if ($a['generate'])
                    <button role="menuitem" type="button" wire:click="generateOne({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Generate</button>
                @endif
                @if ($a['regen_outline'])
                    <button role="menuitem" type="button" wire:click="regenOutline({{ $tid }})" wire:confirm="Regenerate outline?" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Regenerate outline</button>
                @endif
                @if ($a['regen_article'])
                    <button role="menuitem" type="button" wire:click="regenArticle({{ $tid }})" wire:confirm="Regenerate article?" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Regenerate article</button>
                @endif
                @if ($a['regen_image'] && $articleUrl)
                    <a role="menuitem" href="{{ $articleUrl }}" class="block px-3 py-1.5 text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Regenerate image</a>
                @endif
                @if ($a['retry_failed_step'])
                    <button role="menuitem" type="button" wire:click="regenArticle({{ $tid }})" wire:confirm="Retry failed step?" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Retry failed step</button>
                @endif
                @if ($a['improve_note'])
                    <span class="block px-3 py-1.5 text-xs text-gray-400">Improve · manual only</span>
                @endif
            @endif

            @if ($a['has_review'])
                <div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>
                <p class="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">Review</p>
                @if ($a['start_review'])
                    <button role="menuitem" type="button" wire:click="startReviewOne({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Start review</button>
                @endif
                @if ($a['approve'])
                    <button role="menuitem" type="button" wire:click="approveOne({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Approve</button>
                @endif
            @endif

            @if ($a['has_publishing'])
                <div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>
                <p class="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">Publishing</p>
                @if ($a['schedule'])
                    <button role="menuitem" type="button" wire:click="scheduleOne({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Schedule</button>
                @endif
                @if ($a['unschedule'])
                    <button role="menuitem" type="button" wire:click="unscheduleOne({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Unschedule</button>
                @endif
                @if ($a['publish_now'])
                    <button role="menuitem" type="button" wire:click="publishOneNow({{ $tid }})" wire:confirm="Publish now?" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Publish now</button>
                @endif
                @if ($a['retry_publish'])
                    <button role="menuitem" type="button" wire:click="retryPublishOne({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Retry publish</button>
                @endif
                @if ($a['skip'])
                    <button role="menuitem" type="button" wire:click="skipPublishOne({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">Skip</button>
                @endif
                @if ($a['cancel'])
                    <button role="menuitem" type="button" wire:click="cancelPublishOne({{ $tid }})" wire:confirm="Cancel publishing?" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs text-danger-600 hover:bg-danger-50 focus:outline-none dark:text-danger-400 dark:hover:bg-danger-500/10">Cancel</button>
                @endif
            @endif

            <div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>
            <p class="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">Other</p>
            <button role="menuitem" type="button" wire:click="openExecutionDetails({{ $tid }})" @click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800">View details</button>
        </div>
    </div>
</div>
