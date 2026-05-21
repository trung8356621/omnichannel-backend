<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'prompt'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Tùy chỉnh prompt') }}</h1>
                    <p>{{ __('Giọng văn dùng khi soạn prompt; ngưỡng bảng Featured Snippet áp dụng khi chấm điểm SEO và kiểm tra nội dung sau đồng bộ.') }}</p>
                </header>

                <form wire:submit="savePromptSettings" class="max-w-3xl mx-auto space-y-6">
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
