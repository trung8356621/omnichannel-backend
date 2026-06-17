<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'workflows'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Workflows') }}</h1>
                    <p>{{ __('Choose the workflow (Task) used for each request type. Configuration is stored in wp_options.') }}</p>
                </header>

                <form wire:submit="saveSettings" class="max-w-3xl mx-auto space-y-6">
                    {{ $this->form }}

                    <div class="flex justify-end">
                        <x-seo-content-ai::form-save-button
                            target="saveSettings"
                            :label="__('Save settings')"
                        />
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
