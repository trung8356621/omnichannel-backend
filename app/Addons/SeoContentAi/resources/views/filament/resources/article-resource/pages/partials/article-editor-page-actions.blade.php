@php
    $isContentManager = \App\Addons\SeoContentAi\Support\SeoAccessControl::isContentManager();
    $internalPreviewUrl = trim((string) ($this->getArticlePreviewUrl() ?? ''));
    $wpPreviewUrl = (int) ($record->wp_post_id ?? 0) > 0 ? trim((string) $this->getArticlePermalink()) : '';
    $hasWpPreview = $wpPreviewUrl !== '';
    $hasInternalPreview = $internalPreviewUrl !== '';
    $staffSubmitted = $isContentManager && $this->contentManagerSubmittedForReview();
    $reviewButtonActive = $isContentManager ? $staffSubmitted : (bool) $record->is_reviewed;
    $approvedToggleTitle = $isContentManager
        ? __('seo-content-ai::filament.article_list.staff_mark_editing_done')
        : __('seo-content-ai::filament.article_list.mark_reviewed');
    $approveLabel = $reviewButtonActive
        ? ($isContentManager
            ? __('seo-content-ai::filament.article_list.staff_mark_editing_done_already')
            : __('seo-content-ai::filament.article_list.reviewed'))
        : ($isContentManager
            ? $approvedToggleTitle
            : __('seo-content-ai::filament.article_list.page_action_approve_label'));
    $reviewConfirm = $isContentManager
        ? __('seo-content-ai::filament.article_list.staff_mark_editing_done_confirm')
        : __('seo-content-ai::filament.article_list.review_article_description');
    $saveLabel = __('seo-content-ai::filament.article_list.page_action_save_label');
    $syncLabel = __('seo-content-ai::filament.article_list.page_action_sync_label');
    $previewLabel = __('seo-content-ai::filament.article_list.page_action_preview_label');
    $historyUrl = route('seo.articles.revisions.compare', ['article' => $record->getKey()]);
    $promptsUrl = \App\Addons\SeoContentAi\Filament\Resources\ArticleResource::getUrl('prompts', ['record' => $record]);
    $inContentProject = \App\Addons\SeoContentAi\Filament\Resources\ArticleResource::articleIsInContentProject($record);
    $isContentArchived = \App\Addons\SeoContentAi\Filament\Resources\ArticleResource::articleIsContentArchived($record);
    $contentProjectUrl = $inContentProject
        ? \App\Addons\SeoContentAi\Filament\Resources\ArticleResource::articleContentProjectUrl($record)
        : null;
@endphp

{{-- Top bar: Save → Sync → Preview(split) → Approve | More (secondary + delete) --}}
<div
    class="seo-editor-page-actions"
    data-seo-page-actions-slot
    wire:ignore.self
    x-data="{ moreOpen: false, previewOpen: false }"
    x-bind:class="{ 'is-more-open': moreOpen }"
    x-on:click.outside="moreOpen = false; previewOpen = false"
    x-on:keydown.escape.window="moreOpen = false; previewOpen = false"
>
    <div class="seo-editor-page-actions__group seo-editor-page-actions__group--primary" data-seo-page-actions-primary>
        <button
            type="button"
            class="seo-editor-toolbar-btn seo-editor-toolbar-btn--primary seo-editor-toolbar-btn--labeled"
            title="{{ __('seo-content-ai::filament.article_list.page_action_save') }}"
            aria-label="{{ __('seo-content-ai::filament.article_list.page_action_save') }}"
            x-on:click="window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'save' } }))"
            data-seo-page-action="save"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-8H7v8M7 3v5h8" />
            </svg>
            <span class="seo-editor-toolbar-btn__label">{{ $saveLabel }}</span>
        </button>

        @if (! $isContentManager)
            <button
                type="button"
                class="seo-editor-toolbar-btn seo-editor-toolbar-btn--accent seo-editor-toolbar-btn--labeled"
                title="{{ __('seo-content-ai::filament.article_list.sync_to_wordpress') }}"
                aria-label="{{ __('seo-content-ai::filament.article_list.sync_to_wordpress') }}"
                data-seo-page-action="sync"
                x-on:click="window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'sync' } }))"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1.1 15.9-3.3-9.7h2l1.5 5.1 1.5-5.1h1.9l1.5 5.1 1.5-5.1h2l-3.3 9.7h-1.9l-1.5-4.9-1.5 4.9h-1.9z"/>
                </svg>
                <span class="seo-editor-toolbar-btn__label">{{ $syncLabel }}</span>
            </button>
        @endif

        <div class="seo-editor-preview-split seo-editor-page-actions__desktop-only" data-seo-page-action="preview">
            @if ($hasWpPreview)
                <a
                    href="{{ $wpPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled seo-editor-preview-split__main"
                    title="{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $previewLabel }}</span>
                </a>
            @elseif ($hasInternalPreview)
                <a
                    href="{{ $internalPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled seo-editor-preview-split__main"
                    title="{{ __('seo-content-ai::filament.article_list.page_action_preview_internal') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview_internal') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $previewLabel }}</span>
                </a>
            @else
                <button
                    type="button"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled seo-editor-preview-split__main"
                    title="{{ __('seo-content-ai::filament.article_list.page_action_preview') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview') }}"
                    x-on:click="window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'preview' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $previewLabel }}</span>
                </button>
            @endif

            <button
                type="button"
                class="seo-editor-toolbar-btn seo-editor-preview-split__chevron"
                title="{{ __('seo-content-ai::filament.article_list.page_action_preview_menu') }}"
                aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview_menu') }}"
                x-bind:aria-expanded="previewOpen"
                x-on:click.stop="previewOpen = !previewOpen; moreOpen = false"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
                </svg>
            </button>

            <div
                class="seo-editor-preview-split__menu"
                x-show="previewOpen"
                x-cloak
                role="menu"
            >
                @if ($hasWpPreview)
                    <a
                        href="{{ $wpPreviewUrl }}"
                        target="_blank"
                        rel="noopener"
                        role="menuitem"
                        class="seo-editor-menu-item"
                        x-on:click="previewOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                        <span>{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}</span>
                    </a>
                @else
                    <span class="seo-editor-menu-item is-disabled" role="menuitem" aria-disabled="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                        <span>{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}</span>
                    </span>
                @endif

                @if ($hasInternalPreview)
                    <a
                        href="{{ $internalPreviewUrl }}"
                        target="_blank"
                        rel="noopener"
                        role="menuitem"
                        class="seo-editor-menu-item"
                        x-on:click="previewOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        <span>{{ __('seo-content-ai::filament.article_list.page_action_preview_internal') }}</span>
                    </a>
                @endif
            </div>
        </div>

        <button
            type="button"
            wire:click="toggleArticleReview"
            @if (! $reviewButtonActive) wire:confirm="{{ $reviewConfirm }}" @endif
            wire:loading.attr="disabled"
            wire:target="toggleArticleReview,approveArticle"
            @if (! $this->canToggleArticleReview()) disabled @endif
            class="seo-editor-toolbar-btn seo-editor-toolbar-btn--success seo-editor-toolbar-btn--labeled seo-editor-page-actions__desktop-only @if ($reviewButtonActive) is-active @endif"
            title="{{ $reviewButtonActive
                ? ($isContentManager
                    ? __('seo-content-ai::filament.article_list.staff_mark_editing_done_already')
                    : __('seo-content-ai::filament.article_list.reviewed'))
                : $approvedToggleTitle }}"
            aria-label="{{ $approveLabel }}"
            aria-pressed="{{ $reviewButtonActive ? 'true' : 'false' }}"
            data-seo-page-action="review"
        >
            <span wire:loading.remove wire:target="toggleArticleReview,approveArticle" class="seo-editor-toolbar-btn__inner">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span class="seo-editor-toolbar-btn__label">{{ $approveLabel }}</span>
            </span>
            <span wire:loading wire:target="toggleArticleReview,approveArticle" class="seo-editor-toolbar-btn__spinner" aria-hidden="true"></span>
        </button>
    </div>

    {{-- More: History, Prompts, Assign/Open project, Restore, Debug(JS), Delete --}}
    <div class="seo-editor-page-actions__more" data-seo-page-actions-more>
        <button
            type="button"
            class="seo-editor-toolbar-btn"
            title="{{ __('seo-content-ai::filament.article_list.page_action_more') }}"
            aria-label="{{ __('seo-content-ai::filament.article_list.page_action_more') }}"
            x-bind:aria-expanded="moreOpen"
            x-on:click="moreOpen = !moreOpen; previewOpen = false"
            data-seo-page-action="more"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
            </svg>
        </button>

        <div
            class="seo-editor-page-actions__more-panel"
            x-show="moreOpen"
            x-cloak
            role="menu"
            data-seo-page-actions-more-panel
        >
            {{-- Compact: Preview / Approve (tablet+) — cùng handler, chỉ hiện khi desktop primary ẩn --}}
            @if ($hasWpPreview)
                <a
                    href="{{ $wpPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    data-seo-page-action="preview"
                    x-on:click="moreOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span>{{ $previewLabel }}</span>
                </a>
            @elseif ($hasInternalPreview)
                <a
                    href="{{ $internalPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    data-seo-page-action="preview"
                    x-on:click="moreOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span>{{ $previewLabel }}</span>
                </a>
            @else
                <button
                    type="button"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    data-seo-page-action="preview"
                    x-on:click="moreOpen = false; window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'preview' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span>{{ $previewLabel }}</span>
                </button>
            @endif

            <button
                type="button"
                wire:click="toggleArticleReview"
                @if (! $reviewButtonActive) wire:confirm="{{ $reviewConfirm }}" @endif
                wire:loading.attr="disabled"
                wire:target="toggleArticleReview,approveArticle"
                @if (! $this->canToggleArticleReview()) disabled @endif
                class="seo-editor-menu-item seo-editor-page-actions__compact-only @if ($reviewButtonActive) is-active @endif"
                role="menuitem"
                title="{{ $approveLabel }}"
                aria-label="{{ $approveLabel }}"
                data-seo-page-action="review"
                x-on:click="moreOpen = false"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span>{{ $approveLabel }}</span>
            </button>

            <div class="seo-editor-menu-divider seo-editor-page-actions__compact-only" aria-hidden="true"></div>

            <a
                href="{{ $historyUrl }}"
                target="_blank"
                rel="noopener"
                class="seo-editor-menu-item"
                role="menuitem"
                data-seo-page-action="history"
                x-on:click="moreOpen = false"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span>{{ __('seo-content-ai::filament.article_list.page_action_history') }}</span>
            </a>

            <a
                href="{{ $promptsUrl }}"
                target="_blank"
                rel="noopener"
                class="seo-editor-menu-item"
                role="menuitem"
                data-seo-page-action="prompts"
                x-on:click="moreOpen = false"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                </svg>
                <span>Prompts</span>
            </a>

            @if ($inContentProject && filled($contentProjectUrl))
                <a
                    href="{{ $contentProjectUrl }}"
                    class="seo-editor-menu-item"
                    role="menuitem"
                    data-seo-page-action="open-content-project"
                    x-on:click="moreOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.62-.627 1.498-.99 2.427-.99h11.646c.93 0 1.807.363 2.427.99m-16.5 0a2.25 2.25 0 0 0-.245.245l-1.26 1.49A2.25 2.25 0 0 0 3 13.186V18a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 18v-4.814a2.25 2.25 0 0 0-.608-1.525l-1.26-1.49a2.25 2.25 0 0 0-.245-.245m-16.5 0V6.75A2.25 2.25 0 0 1 6 4.5h12A2.25 2.25 0 0 1 20.25 6.75v3.026" />
                    </svg>
                    <span>{{ __('seo-content-ai::filament.article_edit.open_content_project') }}</span>
                </a>
            @elseif (! $isContentArchived)
                <button
                    type="button"
                    class="seo-editor-menu-item"
                    role="menuitem"
                    data-seo-page-action="assign-content-project"
                    x-on:click="moreOpen = false; window.dispatchEvent(new CustomEvent('open-article-assign-content-project-modal'))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                    </svg>
                    <span>{{ __('seo-content-ai::filament.article_list.assign_to_content_project') }}</span>
                </button>
            @endif

            <div class="seo-editor-page-actions__group seo-editor-page-actions__group--secondary" data-seo-page-actions-secondary>
                @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-danger-actions', [
                    'record' => $record,
                    'renderDelete' => false,
                ])
            </div>

            <div class="seo-editor-menu-divider" aria-hidden="true"></div>

            <div class="seo-editor-page-actions__group seo-editor-page-actions__group--danger" data-seo-page-actions-danger>
                @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-danger-actions', [
                    'record' => $record,
                    'renderDelete' => true,
                    'renderRestore' => false,
                ])
            </div>
        </div>
    </div>

    <button
        type="button"
        class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled seo-article-editor-help-btn"
        title="{{ __('seo-content-ai::filament.article_list.page_action_help') }}"
        aria-label="{{ __('seo-content-ai::filament.article_list.page_action_help_aria') }}"
        data-seo-page-action="help"
        x-on:click="window.dispatchEvent(new CustomEvent('article-editor:help-open', { detail: { topic: 'article-editor.overview' } }))"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
        </svg>
        <span class="seo-editor-toolbar-btn__label">Help</span>
    </button>
</div>
