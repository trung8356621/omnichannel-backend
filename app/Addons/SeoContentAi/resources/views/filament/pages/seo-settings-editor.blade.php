<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'editor'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_editor.page_title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_editor.page_description') }}</p>
                </header>

                <form wire:submit="saveEditorSettings" class="max-w-3xl mx-auto space-y-6">
                    {{ $this->form }}

                    <div class="flex justify-end">
                        <x-filament::button type="submit" icon="heroicon-o-check">
                            {{ __('seo-content-ai::filament.settings_editor.save') }}
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
