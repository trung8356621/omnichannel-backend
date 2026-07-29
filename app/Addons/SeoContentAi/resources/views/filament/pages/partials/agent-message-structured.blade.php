@php
    $type = $message['message_type'] ?? 'text';
    $structured = is_array($message['structured_content'] ?? null) ? $message['structured_content'] : [];
@endphp

@if ($type === 'memory_proposal')
    @include('seo-content-ai::filament.pages.partials.agent-workspace.memory-proposal-card', [
        'message' => $message,
        'structured' => $structured,
    ])
@endif

@if ($type === 'knowledge_conflict')
    @include('seo-content-ai::filament.pages.partials.agent-workspace.knowledge-conflict-card', [
        'message' => $message,
        'structured' => $structured,
    ])
@endif

@if ($type === 'planning_status')
    @include('seo-content-ai::filament.pages.partials.agent-workspace.planning-status', [
        'message' => $message,
        'structured' => $structured,
    ])
@endif

@if ($type === 'proposed_intent')
    @include('seo-content-ai::filament.pages.partials.agent-workspace.proposed-intent', [
        'message' => $message,
        'structured' => $structured,
    ])
@endif

@if ($type === 'proposed_plan')
    @include('seo-content-ai::filament.pages.partials.agent-workspace.proposed-plan', [
        'message' => $message,
        'structured' => $structured,
    ])
@endif

@if ($type === 'clarification')
    @include('seo-content-ai::filament.pages.partials.agent-workspace.clarification-card', [
        'message' => $message,
        'structured' => $structured,
    ])
@endif

@if (in_array($type, ['unsupported', 'assistant_answer'], true))
    @include('seo-content-ai::filament.pages.partials.agent-workspace.unsupported-card', [
        'message' => $message,
        'structured' => $type === 'assistant_answer'
            ? array_merge($structured, ['summary' => $message['content'] ?? ($structured['summary'] ?? '')])
            : $structured,
    ])
@endif

@if (in_array($type, ['execution_preview', 'execution_confirmation', 'execution_result', 'execution_error', 'execution_plan'], true))
    @include('seo-content-ai::filament.pages.partials.agent-execution-card', [
        'messageType' => $type,
        'structured' => $structured,
    ])
@endif

@if ($type === 'preview' && $structured !== [])
    <div class="mt-2 space-y-1 text-xs opacity-90">
        <div class="font-semibold">Preview</div>
        @foreach (($structured['input_summary'] ?? []) as $k => $v)
            <div><span class="opacity-70">{{ $k }}:</span> {{ is_scalar($v) ? $v : json_encode($v) }}</div>
        @endforeach
        @foreach (($structured['result']['warnings'] ?? []) as $warning)
            <div class="text-amber-700 dark:text-amber-300">⚠ {{ $warning }}</div>
        @endforeach
    </div>
@endif

@if ($type === 'tool_result')
    <div class="mt-2 rounded-lg border border-black/10 bg-black/5 p-2 text-xs dark:border-white/20 dark:bg-white/10">
        <div class="font-semibold">Completed</div>
        @if (! empty($structured['operation_ref']))
            <div>Operation: {{ $structured['operation_ref'] }}</div>
        @endif
        @foreach (($structured['links'] ?? []) as $link)
            <div class="mt-1">→ {{ $link['label'] ?? 'Open' }} ({{ $link['ref'] ?? '' }})</div>
        @endforeach
    </div>
@endif

@if (! empty($structured['choices']))
    <div class="mt-2 flex flex-wrap gap-2">
        @foreach ($structured['choices'] as $choice)
            <x-seo-content-ai::agent-workspace.action-button
                action="selectSkill"
                :value="$choice['skill_key'] ?? ''"
                class="rounded-lg bg-white px-2 py-1 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-white"
            >
                {{ $choice['name'] }}
            </x-seo-content-ai::agent-workspace.action-button>
        @endforeach
    </div>
@endif

@if (! empty($structured['plan_steps']))
    <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs">
        @foreach ($structured['plan_steps'] as $step)
            <li>
                <x-seo-content-ai::agent-workspace.action-button
                    action="selectSkill"
                    :value="$step['skill_key'] ?? ''"
                    class="underline"
                >
                    {{ $step['title'] }}
                </x-seo-content-ai::agent-workspace.action-button>
            </li>
        @endforeach
    </ol>
@endif

@if (! empty($structured['help_groups']))
    <div class="mt-2 space-y-2">
        @foreach ($structured['help_groups'] as $group)
            <div>
                <div class="text-xs font-semibold uppercase opacity-70">{{ $group['group'] }}</div>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach ($group['items'] as $item)
                        <x-seo-content-ai::agent-workspace.action-button
                            action="selectSkill"
                            :value="$item['skill_key'] ?? ''"
                            class="rounded-lg bg-white px-2 py-1 text-xs text-gray-800 dark:bg-gray-700 dark:text-white"
                        >
                            {{ $item['name'] }}
                        </x-seo-content-ai::agent-workspace.action-button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
