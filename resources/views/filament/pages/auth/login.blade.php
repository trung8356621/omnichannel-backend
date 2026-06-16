<x-filament-panels::page.simple>
    <x-filament-panels::form wire:submit="authenticate">
        {{ $this->form }}
        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()" />
    </x-filament-panels::form>

    <div class="relative flex py-5 items-center">
        <div class="flex-grow border-t border-gray-700"></div>
        <span class="flex-shrink mx-4 text-gray-500 text-xs uppercase">Hoặc</span>
        <div class="flex-grow border-t border-gray-700"></div>
    </div>

    <x-filament::button color="gray" icon="heroicon-m-globe-alt" tag="a"
        href="{{ route('google.login', ['return_url' => method_exists($this, 'getGoogleLoginReturnUrl') ? $this->getGoogleLoginReturnUrl() : (request()->query('return_url') ?: filament()->getCurrentPanel()->getUrl())]) }}"
        class="w-full">
        Tiếp tục với Google
    </x-filament::button>
</x-filament-panels::page.simple>
