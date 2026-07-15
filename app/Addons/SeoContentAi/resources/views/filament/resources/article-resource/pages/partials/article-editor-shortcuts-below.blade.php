{{-- Keyboard shortcuts — below editor workspace, collapsible, not in page toolbar --}}
<div
    class="seo-editor-shortcuts-below"
    x-data="{
        open: (() => {
            try {
                const raw = localStorage.getItem('seo.articleEditor.shortcutsOpen');
                if (raw === null) return false;
                return raw === '1';
            } catch (e) {
                return false;
            }
        })(),
        toggle() {
            this.open = !this.open;
            try {
                localStorage.setItem('seo.articleEditor.shortcutsOpen', this.open ? '1' : '0');
            } catch (e) {}
        },
    }"
    data-seo-shortcuts-below
>
    <button
        type="button"
        class="seo-editor-shortcuts-below__toggle"
        x-on:click="toggle()"
        x-bind:aria-expanded="open"
        title="{{ __('seo-content-ai::filament.article_list.page_action_shortcuts') }}"
        aria-label="{{ __('seo-content-ai::filament.article_list.page_action_shortcuts') }}"
        data-seo-shortcuts-wrap
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
            <rect x="2" y="6" width="20" height="12" rx="2" stroke-width="1.75" />
            <path stroke-linecap="round" stroke-width="1.75" d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8" />
        </svg>
        <span>{{ __('seo-content-ai::filament.article_list.page_action_shortcuts') }}</span>
        <svg
            class="seo-editor-shortcuts-below__chevron"
            x-bind:class="open ? 'is-open' : ''"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
        </svg>
    </button>

    <div
        class="seo-editor-shortcuts-below__panel"
        x-show="open"
        x-cloak
    >
        @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-shortcuts-panel')
    </div>
</div>
