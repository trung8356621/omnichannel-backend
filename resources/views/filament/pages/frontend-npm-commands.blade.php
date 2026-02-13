<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Chọn project --}}
        <x-filament::section>
            <x-slot name="heading">Chọn project</x-slot>
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-[200px] flex-1">
                    <label class="filament-forms-field-widget-label mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                        Project
                    </label>
                    <select
                        wire:model.live="projectId"
                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 dark:text-white focus:ring-2 focus:ring-primary-500"
                    >
                        <option value="">-- Chọn project --</option>
                        @foreach($this->getProjects() as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->type }})</option>
                        @endforeach
                    </select>
                </div>
                @if($project)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Đường dẫn: <code class="rounded bg-gray-100 dark:bg-gray-800 px-1">{{ $project->package_json_path }}</code>
                    </p>
                @endif
            </div>
        </x-filament::section>

        @if($project)
        <div class="flex">
            {{-- Layout 2 cột: trái = lệnh NPM, phải = terminal đen (log real-time) --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 min-h-0">
                {{-- Cột trái: npm install + danh sách scripts --}}
                <x-filament::section class="overflow-auto">
                    <x-slot name="heading">Lệnh NPM – {{ $project->name }}</x-slot>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Scripts từ <code class="rounded bg-gray-100 dark:bg-gray-800 px-1">package.json</code>. Bấm nút để chạy; log hiển thị real-time bên phải.
                    </p>

                    <div class="mb-6">
                        <button
                            type="button"
                            wire:click="runNpmInstall"
                            wire:loading.attr="disabled"
                            class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus:ring-2 rounded-lg fi-btn-color-primary fi-btn-size-md gap-x-2 inline-grid shadow-sm bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 border border-transparent dark:border-white/20 px-4 py-2 text-sm"
                        >
                            <span wire:loading.remove wire:target="runNpmInstall">npm install</span>
                            <span wire:loading wire:target="runNpmInstall">Đang chạy...</span>
                        </button>
                    </div>

                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Scripts trong package.json</h3>
                    @if(count($scripts) > 0)
                        <div class="grid gap-3 sm:grid-cols-1">
                            @foreach($scripts as $name => $command)
                                <div
                                    wire:key="script-{{ md5($name) }}"
                                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-gray-800/50 p-4"
                                >
                                    <div class="min-w-0 flex-1">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $name }}</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 break-all">{{ $command }}</p>
                                    </div>
                                    <div class="flex gap-2 shrink-0">
                                        @php
                                            $scriptParam = str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $name);
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="runScript('{{ $scriptParam }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="runScript"
                                            class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus:ring-2 rounded-lg fi-btn-size-sm gap-x-2 inline-grid shadow-sm bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 border border-gray-200 dark:border-white/10 px-3 py-1.5 text-xs"
                                        >
                                            Chạy
                                        </button>
                                        @if(in_array(strtolower($name), ['dev', 'start'], true))
                                            <button
                                                type="button"
                                                wire:click="runScriptInBackground('{{ $scriptParam }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="runScriptInBackground"
                                                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus:ring-2 rounded-lg fi-btn-size-sm gap-x-2 inline-grid shadow-sm bg-success-600 text-white hover:bg-success-500 dark:bg-success-500 dark:hover:bg-success-400 border border-transparent dark:border-white/20 px-3 py-1.5 text-xs"
                                            >
                                                Chạy nền
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-amber-600 dark:text-amber-400 rounded-lg bg-amber-50 dark:bg-amber-950/30 p-4">
                            Không đọc được scripts. Kiểm tra đường dẫn có file <code>package.json</code> và có mục <code>scripts</code>.
                        </p>
                    @endif
                </x-filament::section>

                {{-- Cột phải: màn hình đen capture log real-time --}}
                <x-filament::section class="flex flex-col min-h-[420px] xl:min-h-[520px]">
                    <x-slot name="heading">Output (real-time)</x-slot>
                    <div class="flex-1 rounded-lg bg-[#1e1e1e] dark:bg-black border border-gray-700 overflow-hidden flex flex-col">
                        <pre
                            wire:stream="terminalOutput"
                            class="flex-1 p-4 text-sm text-gray-100 font-mono whitespace-pre-wrap break-words overflow-auto min-h-0 m-0"
                        ></pre>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Log từ lệnh npm sẽ xuất hiện ở đây khi bạn bấm Chạy hoặc npm install.</p>
                </x-filament::section>
            </div>

            @if(!$this->getProjects()->isEmpty() && count($scripts) === 0)
                <p class="text-sm text-amber-600 dark:text-amber-400">
                    Chưa có scripts. Đảm bảo thư mục project có <code>package.json</code> với mục <code>scripts</code>.
                </p>
            @endif
        @else
            @if($this->getProjects()->isEmpty())
                <x-filament::section>
                    <p class="text-gray-500 dark:text-gray-400">
                        Chưa có project nào. <a href="{{ \App\Filament\Resources\FrontendProjectResource::getUrl('create') }}" class="text-primary-600 dark:text-primary-400 hover:underline">Thêm project frontend</a> (Next.js/React) và khai báo đường dẫn thư mục chứa package.json.
                    </p>
                </x-filament::section>
            @else
                <x-filament::section>
                    <p class="text-gray-500 dark:text-gray-400">Chọn một project ở trên để xem scripts và chạy lệnh NPM.</p>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
