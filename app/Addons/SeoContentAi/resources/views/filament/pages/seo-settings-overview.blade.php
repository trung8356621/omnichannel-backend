<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'overview'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Tổng quan') }}</h1>
                    <p>{{ __('Cài đặt chung cho nhận diện nội dung khi đồng bộ và xử lý bài viết.') }}</p>
                </header>

                <form wire:submit="saveOverviewSettings" class="max-w-3xl mx-auto space-y-6">
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
