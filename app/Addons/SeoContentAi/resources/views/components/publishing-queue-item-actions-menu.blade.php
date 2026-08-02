@props([
    'row' => [],
])

@php
    $tid = (int) ($row['task_id'] ?? 0);
    $articleUrl = $row['article_edit_url'] ?? null;
    $a = \App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueItemActionsPresenter::forRow($row);
    $itemClass = 'cp-ops-menu__item';
    $dangerClass = 'cp-ops-menu__item cp-ops-menu__item--danger';
@endphp

<x-seo-content-ai::content-project-action-menu-shell {{ $attributes }}>
    @if ($a['has_publishing'])
        <p class="cp-ops-menu__heading">Publishing</p>
        @if ($a['schedule'])
            <button role="menuitem" type="button" wire:click="scheduleOne({{ $tid }})" @click="open = false" class="{{ $itemClass }}" title="Schedule (+1h)">
                <x-filament::icon icon="heroicon-o-calendar-days" class="cp-ops-menu__icon" />
                <span class="cp-ops-menu__label">Schedule (+1h)</span>
            </button>
        @endif
        @if ($a['unschedule'])
            <button role="menuitem" type="button" wire:click="unscheduleOne({{ $tid }})" @click="open = false" class="{{ $itemClass }}" title="Unschedule">
                <x-filament::icon icon="heroicon-o-calendar" class="cp-ops-menu__icon" />
                <span class="cp-ops-menu__label">Unschedule</span>
            </button>
        @endif
        @if ($a['publish_now'])
            <button role="menuitem" type="button" wire:click="publishOneNow({{ $tid }})" wire:confirm="Publish now?" @click="open = false" class="{{ $itemClass }}" title="Publish now">
                <x-filament::icon icon="heroicon-o-globe-alt" class="cp-ops-menu__icon" />
                <span class="cp-ops-menu__label">Publish now</span>
            </button>
        @endif
        @if ($a['retry_publish'])
            <button role="menuitem" type="button" wire:click="retryPublishOne({{ $tid }})" @click="open = false" class="{{ $itemClass }}" title="Retry">
                <x-filament::icon icon="heroicon-o-arrow-path" class="cp-ops-menu__icon" />
                <span class="cp-ops-menu__label">Retry</span>
            </button>
        @endif
    @endif

    @if ($a['has_lifecycle'])
        <div class="cp-ops-menu__divider"></div>
        <p class="cp-ops-menu__heading">Lifecycle</p>
        @if ($a['return_to_content_project'])
            <button role="menuitem" type="button" wire:click="returnOne({{ $tid }})" wire:confirm="Return to Content Project?" @click="open = false" class="{{ $itemClass }}" title="Return to Content Project">
                <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="cp-ops-menu__icon" />
                <span class="cp-ops-menu__label">Return to Content Project</span>
            </button>
        @endif
        @if ($a['cancel'])
            <button role="menuitem" type="button" wire:click="cancelPublishOne({{ $tid }})" wire:confirm="Cancel publishing?" @click="open = false" class="{{ $dangerClass }}" title="Cancel">
                <x-filament::icon icon="heroicon-o-x-mark" class="cp-ops-menu__icon" />
                <span class="cp-ops-menu__label">Cancel</span>
            </button>
        @endif
    @endif

    @if ($a['has_other'])
        <div class="cp-ops-menu__divider"></div>
        <p class="cp-ops-menu__heading">Other</p>
        @if ($a['open_article'] && $articleUrl)
            <a role="menuitem" href="{{ $articleUrl }}" @click="open = false" class="{{ $itemClass }}" title="Open article">
                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="cp-ops-menu__icon" />
                <span class="cp-ops-menu__label">Open article</span>
            </a>
        @endif
    @endif
</x-seo-content-ai::content-project-action-menu-shell>
