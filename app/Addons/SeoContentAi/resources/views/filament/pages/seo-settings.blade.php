<x-filament-panels::page>
    <form wire:submit="saveSettings" class="space-y-6 max-w-3xl">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-check">
            Lưu cấu hình
        </x-filament::button>
    </form>
</x-filament-panels::page>
