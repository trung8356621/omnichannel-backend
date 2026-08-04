@props([
    'row' => [],
    'disabled' => false,
])

@php
    $tid = (int) ($row['task_id'] ?? 0);
    $articleUrl = $row['article_edit_url'] ?? null;
    $a = \App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueItemActionsPresenter::forRow($row);
    $itemClass = 'cp-ops-menu__item';
    $dangerClass = 'cp-ops-menu__item cp-ops-menu__item--danger';
@endphp

{{-- Inline dropdown shell (same Alpine as content-project-item-actions-menu). Avoid nested seo-content-ai::content-project-action-menu-shell — remote may lack that view. --}}
<div
    {{ $attributes->class([
        'relative inline-flex items-center gap-1',
        'pointer-events-none opacity-50' => (bool) $disabled,
    ]) }}
    x-data="{
        open: false,
        place: 'bottom-end',
        style: '',
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.reposition());
            } else {
                this.style = '';
            }
        },
        reposition() {
            const panel = this.$refs.menu;
            const btn = this.$refs.trigger;
            if (!panel || !btn) return;
            const br = btn.getBoundingClientRect();
            const pw = Math.min(280, Math.max(240, panel.offsetWidth || 240));
            const ph = panel.offsetHeight || 240;
            const spaceBelow = window.innerHeight - br.bottom;
            const flipUp = spaceBelow < ph + 12 && br.top > ph + 12;
            let top = flipUp ? (br.top - ph - 4) : (br.bottom + 4);
            let left = br.right - pw;
            if (left < 12) left = 12;
            if (left + pw > window.innerWidth - 12) left = Math.max(12, window.innerWidth - pw - 12);
            if (top < 12) top = 12;
            this.place = (flipUp ? 'top' : 'bottom') + '-end';
            this.style = 'position:fixed;top:' + top + 'px;left:' + left + 'px;right:auto;bottom:auto;';
        },
    }"
    @keydown.escape.window="open = false"
>
    <div class="relative">
        <button
            type="button"
            x-ref="trigger"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:ring-gray-700 dark:hover:bg-gray-800"
            @click="toggle()"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-label="Item actions"
            @disabled($disabled)
        >
            <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-4 w-4" />
        </button>

        <div
            x-ref="menu"
            x-show="open"
            x-cloak
            x-transition
            @click.outside="open = false"
            role="menu"
            class="cp-ops-menu"
            :style="style"
            :class="{
                'cp-ops-menu--top': place.startsWith('top'),
                'cp-ops-menu--bottom': place.startsWith('bottom'),
                'cp-ops-menu--start': place.endsWith('start'),
                'cp-ops-menu--end': place.endsWith('end'),
            }"
        >
            @if ($disabled)
                <p class="cp-ops-menu__heading">{{ __('seo-content-ai::filament.projects.publishing_queue_pending_updating') }}</p>
            @else
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
            @endif
        </div>
    </div>
</div>
