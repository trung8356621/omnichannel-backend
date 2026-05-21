<x-filament-panels::page>
    <form wire:submit="saveDomainPromptContext" class="space-y-6 max-w-3xl">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-check">
            Lưu thông tin
        </x-filament::button>
    </form>
</x-filament-panels::page>
