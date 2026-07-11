<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'api'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.api_connections.title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.api_connections.subtitle') }}</p>
                </header>

                @if (count($this->getCachedHeaderActions()))
                    <div class="mb-4 flex justify-end">
                        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
                    </div>
                @endif

                <div class="seo-settings-ai-table">
                    {{ $this->table }}
                </div>
            </div>
        </div>
    </x-filament-panels::page>
</div>
