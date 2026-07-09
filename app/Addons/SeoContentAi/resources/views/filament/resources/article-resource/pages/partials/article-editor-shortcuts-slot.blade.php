<div wire:ignore class="seo-editor-page-actions" data-seo-page-actions-slot>
    @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-danger-actions', ['record' => $record])

    <div class="relative" data-seo-shortcuts-wrap x-data="{ shortcutsOpen: false }">
        <button
            type="button"
            x-on:click="shortcutsOpen = !shortcutsOpen"
            class="article-editor-shortcuts-trigger"
            title="Phím tắt"
            aria-label="Phím tắt"
            x-bind:aria-expanded="shortcutsOpen"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                <rect x="2" y="6" width="20" height="12" rx="2" stroke-width="1.75" />
                <path stroke-linecap="round" stroke-width="1.75" d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8" />
            </svg>
        </button>

        <div
            x-show="shortcutsOpen"
            x-cloak
            x-on:click.outside="shortcutsOpen = false"
            x-on:keydown.escape.window="shortcutsOpen = false"
            class="article-editor-shortcuts-popover"
            role="dialog"
            aria-label="Danh sách phím tắt"
        >
            @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-shortcuts-panel')
        </div>
    </div>
</div>
