export function callEditArticleLivewire(method, ...args) {
    if (typeof Livewire === 'undefined') {
        return Promise.reject(new Error('Livewire is not available'));
    }

    const host = document.querySelector('[wire\\:id]');
    const wireId = host?.getAttribute('wire:id');
    if (!wireId) {
        return Promise.reject(new Error('Livewire component not found'));
    }

    const component = Livewire.find(wireId);
    if (!component?.call) {
        return Promise.reject(new Error('Livewire component not callable'));
    }

    return component.call(method, ...args);
}
