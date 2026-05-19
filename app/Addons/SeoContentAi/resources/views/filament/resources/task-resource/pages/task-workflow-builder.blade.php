<x-filament-panels::page>
    @php
        $flow = $this->getFlowData();
    @endphp

    <script>
        window.__SEO_PROMPTS__ = @json($this->getPromptsForBuilder());
    </script>

    <div
        x-data
        x-on:save-task-flow.window="$wire.saveFlow($event.detail)"
        class="w-full h-[calc(100vh-100px)] -mt-6 -mx-6 rounded-xl overflow-hidden"
    >
        <script type="application/json" id="seo-task-initial-flow">@json($flow)</script>

        <div
            wire:ignore
            id="seo-task-workflow-builder-root"
            data-task-id="{{ $this->taskId }}"
            data-task-name="{{ $this->getTaskName() }}"
            class="w-full h-full"
        ></div>
    </div>

    @push('scripts')
        @viteReactRefresh
        @vite('app/Addons/SeoContentAi/resources/js/task-builder.jsx')
    @endpush
</x-filament-panels::page>
