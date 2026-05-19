<x-filament-panels::page>
    <div class="seo-task-test space-y-6 max-w-5xl">
        <x-filament::section heading="Đầu vào bài viết">
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                <strong>Chọn bài viết</strong> từ danh sách (mọi domain của bạn), hoặc nhập <strong>tiêu đề / từ khóa</strong> vào một ô.
                Khi không chọn bài, hệ thống tìm theo thứ tự <strong>tiêu đề → từ khóa</strong>; không khớp thì coi là <strong>tạo bài mới</strong>.
            </p>

            <form wire:submit="runTest" class="space-y-4">
                {{ $this->form }}

                <x-filament::button
                    type="submit"
                    icon="heroicon-o-play"
                    color="success"
                    wire:loading.attr="disabled"
                    wire:target="runTest"
                >
                    <span wire:loading.remove wire:target="runTest">Chạy thử quy trình</span>
                    <span wire:loading wire:target="runTest">Đang chạy…</span>
                </x-filament::button>
            </form>
        </x-filament::section>

        @if (filled($errorMessage))
            <x-filament::section heading="Lỗi">
                <p class="text-sm text-danger-600 dark:text-danger-400">{{ $errorMessage }}</p>
            </x-filament::section>
        @endif

        @if (is_array($resolvedContext))
            <x-filament::section heading="Ngữ cảnh đã giải quyết">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">{{ $resolvedContext['summary'] ?? '' }}</p>
                @if (! empty($resolvedContext['variables']))
                    <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                        @foreach ($resolvedContext['variables'] as $key => $value)
                            <div><code>{{ $key }}</code>: {{ $value }}</div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if ($stepResults !== [])
            <x-filament::section heading="Các bước quy trình">
                <ul class="space-y-4">
                    @foreach ($stepResults as $index => $step)
                        @php
                            $status = (string) ($step['status'] ?? 'pending');
                            $badgeClass = match ($status) {
                                'completed', 'ok' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
                                'failed' => 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
                                'skipped' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                default => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
                            };
                        @endphp
                        <li class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="text-xs font-bold text-gray-500">#{{ $index + 1 }}</span>
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $step['title'] ?? 'Bước' }}</span>
                                <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full {{ $badgeClass }}">{{ $status }}</span>
                                @if (! empty($step['prompt_name']))
                                    <span class="text-xs text-violet-600 dark:text-violet-400">{{ $step['prompt_name'] }}</span>
                                @endif
                            </div>
                            @if (! empty($step['message']))
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ $step['message'] }}</p>
                            @endif
                            @if (! empty($step['output']))
                                <div class="seo-task-test-pre-wrap">
                                    <pre class="seo-task-test-pre text-xs">{{ $step['output'] }}</pre>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>

    <style>
        .seo-task-test-pre-wrap {
            max-height: 320px;
            overflow: auto;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .dark .seo-task-test-pre-wrap {
            border-color: #374151;
            background: #111827;
        }
        .seo-task-test-pre {
            margin: 0;
            padding: 0.75rem 1rem;
            white-space: pre-wrap;
            word-break: break-word;
            color: #111827;
        }
        .dark .seo-task-test-pre {
            color: #e5e7eb;
        }
    </style>
</x-filament-panels::page>
