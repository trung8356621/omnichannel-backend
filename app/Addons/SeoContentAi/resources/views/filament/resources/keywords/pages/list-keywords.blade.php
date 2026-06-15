<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    {{ $this->table }}

    @php
        $keywordDestinationsModalConfig = [
            'livewireId' => $this->getId(),
            'headingPrefix' => __('seo-content-ai::filament.keyword.destinations_modal_heading_prefix'),
            'loadingLabel' => __('seo-content-ai::filament.keyword.destinations_modal_loading'),
            'errorLabel' => __('seo-content-ai::filament.keyword.destinations_modal_error'),
        ];
    @endphp

    <script type="application/json" id="keyword-destinations-modal-config">
        @json($keywordDestinationsModalConfig)
    </script>

    <div
        id="keyword-destinations-modal"
        class="keyword-destinations-modal"
        wire:ignore.self
        aria-hidden="true"
        aria-labelledby="keyword-destinations-modal-title"
        role="dialog"
    >
        <div class="keyword-destinations-modal__backdrop" data-keyword-destinations-modal-close></div>
        <div class="keyword-destinations-modal__dialog">
            <header class="keyword-destinations-modal__header">
                <div class="keyword-destinations-modal__header-text">
                    <h2 id="keyword-destinations-modal-title" class="keyword-destinations-modal__title">
                        {{ __('seo-content-ai::filament.keyword.destinations_modal_heading_prefix') }}
                    </h2>
                </div>
                <button
                    type="button"
                    class="keyword-destinations-modal__icon-close"
                    data-keyword-destinations-modal-close
                    aria-label="{{ __('seo-content-ai::filament.keyword.destinations_modal_close') }}"
                >
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>
            </header>

            <div class="keyword-destinations-modal__body">
                <div id="keyword-destinations-modal-loading" class="keyword-destinations-modal__loading keyword-destinations-modal__loading--hidden">
                    <x-filament::loading-indicator class="h-8 w-8" />
                    <span>{{ __('seo-content-ai::filament.keyword.destinations_modal_loading') }}</span>
                </div>
                <p id="keyword-destinations-modal-error" class="keyword-destinations-modal__error keyword-destinations-modal__error--hidden"></p>
                <div id="keyword-destinations-modal-content" class="keyword-destinations-modal__content"></div>
            </div>

            <footer class="keyword-destinations-modal__footer">
                <button
                    type="button"
                    class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray fi-btn-size-md fi-btn-outlined gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20"
                    data-keyword-destinations-modal-close
                >
                    {{ __('seo-content-ai::filament.keyword.destinations_modal_close') }}
                </button>
            </footer>
        </div>
    </div>

    @vite('app/Addons/SeoContentAi/resources/js/keyword-destinations-modal.jsx')

    @once
        <style>
            .keyword-destinations-modal {
                position: fixed;
                inset: 0;
                z-index: 120;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }

            .keyword-destinations-modal.is-open {
                display: flex;
            }

            body.keyword-destinations-modal-open {
                overflow: hidden;
            }

            .keyword-destinations-modal__backdrop {
                position: absolute;
                inset: 0;
                background: rgb(0 0 0 / 0.5);
            }

            .keyword-destinations-modal__dialog {
                position: relative;
                z-index: 1;
                display: flex;
                flex-direction: column;
                width: min(48rem, 100%);
                max-height: min(90vh, 720px);
                background: #fff;
                border-radius: 0.75rem;
                box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
                overflow: hidden;
            }

            .dark .keyword-destinations-modal__dialog {
                background: rgb(17 24 39);
            }

            .keyword-destinations-modal__header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1rem;
                padding: 1.25rem 1.5rem;
                border-bottom: 1px solid rgb(229 231 235);
            }

            .dark .keyword-destinations-modal__header {
                border-color: rgb(55 65 81);
            }

            .keyword-destinations-modal__title {
                margin: 0;
                font-size: 1.125rem;
                font-weight: 600;
                color: rgb(17 24 39);
                word-break: break-word;
            }

            .dark .keyword-destinations-modal__title {
                color: rgb(243 244 246);
            }

            .keyword-destinations-modal__icon-close {
                flex-shrink: 0;
                display: inline-flex;
                padding: 0.375rem;
                border: none;
                border-radius: 0.5rem;
                background: transparent;
                color: rgb(107 114 128);
                cursor: pointer;
            }

            .keyword-destinations-modal__icon-close:hover {
                background: rgb(243 244 246);
                color: rgb(17 24 39);
            }

            .keyword-destinations-modal__body {
                flex: 1;
                overflow-y: auto;
                padding: 1rem 1.5rem;
                min-height: 10rem;
            }

            .keyword-destinations-modal__loading {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.75rem;
                padding: 3rem 1rem;
                font-size: 0.875rem;
                color: rgb(107 114 128);
            }

            .keyword-destinations-modal__loading--hidden {
                display: none !important;
            }

            .keyword-destinations-modal__error--hidden {
                display: none !important;
            }

            .keyword-destinations-modal__error {
                margin: 0 0 1rem;
                padding: 1rem;
                border-radius: 0.5rem;
                background: rgb(254 226 226);
                color: rgb(185 28 28);
                font-size: 0.875rem;
            }

            .keyword-destinations-modal__footer {
                display: flex;
                flex-wrap: wrap;
                justify-content: flex-end;
                gap: 0.75rem;
                padding: 1rem 1.5rem;
                border-top: 1px solid rgb(229 231 235);
            }

            .dark .keyword-destinations-modal__footer {
                border-color: rgb(55 65 81);
            }
        </style>
    @endonce
</x-filament-panels::page>
