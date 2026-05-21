<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'workflows'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Quy trình') }}</h1>
                    <p>{{ __('Chọn quy trình (Task) tương ứng khi hệ thống nhận từng loại yêu cầu. Cấu hình lưu trong wp_options.') }}</p>
                </header>

                <form wire:submit="saveSettings" class="max-w-3xl mx-auto space-y-6">
                    {{ $this->form }}

                    <div class="flex justify-end">
                        <x-filament::button type="submit" icon="heroicon-o-check">
                            {{ __('Lưu cấu hình') }}
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
