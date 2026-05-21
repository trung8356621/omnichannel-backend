<x-filament-panels::page>
    <div class="seo-prompt-test-layout">
        <div class="seo-prompt-test-main">
            <x-filament::section heading="Biến đầu vào">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Model AI được chọn trên widget <strong>Khối Prompt</strong> trong Workflow Builder.
                    Biến <code>{{input}}</code> nhận kết quả từ edge nối vào khi chạy quy trình.
                </p>
                <form wire:submit="runTest" class="space-y-4">
                    {{ $this->form }}

                    <x-filament::button
                        type="submit"
                        icon="heroicon-o-play"
                        wire:loading.attr="disabled"
                        wire:target="runTest"
                    >
                        <span wire:loading.remove wire:target="runTest">Chạy thử</span>
                        <span wire:loading wire:target="runTest">Đang gọi AI…</span>
                    </x-filament::button>
                </form>
            </x-filament::section>

            <x-filament::section heading="Prompt đã ghép (xem trước)">
                <x-slot name="description">
                    Ghép từ cấu hình prompt hiện tại trên database (sau khi bạn lưu ở trang Sửa). Kết quả AI bên dưới có thể từ lần chạy cũ trong sidebar.
                </x-slot>
                @if (filled($compiledPreview))
                    <div class="seo-prompt-test-pre-wrap">
                        <pre class="seo-prompt-test-pre">{{ $compiledPreview }}</pre>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Chưa có nội dung xem trước. Nhấn «Làm mới xem trước» hoặc «Chạy thử».
                    </p>
                @endif
            </x-filament::section>

            @if (filled($errorMessage))
                <x-filament::section heading="Lỗi">
                    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $errorMessage }}</p>
                </x-filament::section>
            @endif

            @if (filled($outputText))
                <x-filament::section :heading="$this->aiResultSectionHeading()">
                    <div class="seo-prompt-test-pre-wrap">
                        <pre class="seo-prompt-test-pre">{{ $outputText }}</pre>
                    </div>

                </x-filament::section>

                <x-filament::section heading="Test">
                    <x-slot name="description">
                        Áp dụng kết quả AI lên bài đã đồng bộ WordPress. Chọn bài đích rồi chọn loại đăng.
                    </x-slot>

                    <div class="space-y-4">
                        <div>
                                <label class="text-xs font-semibold text-gray-600 dark:text-gray-300 block mb-1">Bài viết / sản phẩm đích</label>
                                <select
                                    wire:model="publishArticleId"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-gray-100 px-3 py-2"
                                >
                                    <option value="">— Chọn bài đã đồng bộ WP —</option>
                                    @foreach ($this->articlesForCommentPublish as $article)
                                        <option value="{{ $article->id }}">
                                            [WP #{{ $article->wp_post_id }}] {{ $article->title }}
                                            ({{ $article->type === 'product' ? 'product' : 'post' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        <div
                            class="seo-prompt-test-publish"
                            x-data="{ open: false }"
                            @keydown.escape.window="open = false"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::button
                                    type="button"
                                    icon="heroicon-o-arrow-up-tray"
                                    color="success"
                                    wire:loading.attr="disabled"
                                    wire:target="publishTest"
                                    @click="open = !open"
                                >
                                    <span wire:loading.remove wire:target="publishTest">Đăng…</span>
                                    <span wire:loading wire:target="publishTest">Đang xử lý…</span>
                                </x-filament::button>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Chọn một trong ba tùy chọn</span>
                            </div>

                            <div
                                x-show="open"
                                x-cloak
                                @click.outside="open = false"
                                class="seo-prompt-test-publish-menu"
                            >
                                <button
                                    type="button"
                                    class="seo-prompt-test-publish-menu__item"
                                    wire:click="publishTest('skeleton')"
                                    wire:loading.attr="disabled"
                                    wire:target="publishTest"
                                    @click="open = false"
                                >
                                    <span class="seo-prompt-test-publish-menu__title">1. Đăng sườn bài viết</span>
                                    <span class="seo-prompt-test-publish-menu__desc">Dàn ý Markdown + từ khóa ngữ nghĩa → meta (outline, keywords).</span>
                                </button>
                                <button
                                    type="button"
                                    class="seo-prompt-test-publish-menu__item"
                                    wire:click="publishTest('article')"
                                    wire:loading.attr="disabled"
                                    wire:target="publishTest"
                                    @click="open = false"
                                >
                                    <span class="seo-prompt-test-publish-menu__title">2. Đăng bài viết</span>
                                    <span class="seo-prompt-test-publish-menu__desc">Từ dàn ý tạo HTML + tiêu đề (biến hoặc H1/H2 đầu).</span>
                                </button>
                                <button
                                    type="button"
                                    class="seo-prompt-test-publish-menu__item"
                                    wire:click="publishTest('reviews')"
                                    wire:loading.attr="disabled"
                                    wire:target="publishTest"
                                    @click="open = false"
                                >
                                    <span class="seo-prompt-test-publish-menu__title">3. Đăng review / bình luận</span>
                                    <span class="seo-prompt-test-publish-menu__desc">JSON kết quả AI → comment/review trên WordPress (sao WC nếu thiếu <code>star_ranking</code>).</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            @endif
        </div>

        <aside class="seo-prompt-test-sidebar">
            <div class="seo-prompt-test-sidebar__head">
                <h2 class="seo-prompt-test-sidebar__title">Lịch sử chạy thử</h2>
                <p class="seo-prompt-test-sidebar__meta">{{ $this->promptResults->count() }} lần gần đây</p>
            </div>

            <ul class="seo-prompt-test-history">
                @forelse ($this->promptResults as $result)
                    @php
                        $isActive = $selectedResultId === $result->id;
                        $status = (string) $result->status;
                        $statusClass = match ($status) {
                            'completed' => 'completed',
                            'failed' => 'failed',
                            default => 'pending',
                        };
                    @endphp
                    <li class="seo-prompt-test-history__item">
                        <div class="seo-history-row">
                            <button
                                type="button"
                                wire:click="selectResult({{ $result->id }})"
                                class="seo-history-card seo-history-card--{{ $statusClass }}{{ $isActive ? ' is-active' : '' }}"
                            >
                                <span class="seo-history-card__grid">
                                    <span class="seo-history-card__summary">
                                        {{ $this->resultSummary($result) }}@if ($tokenLabel = $this->tokenUsageLabelFor($result)) <span class="seo-history-card__tokens">({{ $tokenLabel }})</span>@endif
                                    </span>
                                    <span class="seo-history-card__meta">
                                        <span class="seo-history-card__time">
                                            {{ ($result->finished_at ?? $result->created_at)?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                                        </span>
                                        <span class="seo-history-card__badge seo-history-card__badge--{{ $statusClass }}">
                                        @if ($status === 'completed')
                                            <svg class="seo-history-card__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                                            Thành công
                                        @elseif ($status === 'failed')
                                            <svg class="seo-history-card__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
                                            Lỗi
                                        @else
                                            Đang chạy…
                                        @endif
                                        </span>
                                    </span>
                                </span>
                            </button>
                            <button
                                type="button"
                                class="seo-history-delete"
                                title="Xóa lần chạy thử"
                                wire:click.stop="deleteResult({{ $result->id }})"
                                wire:confirm="Xóa lần chạy thử này? Hành động không thể hoàn tác."
                                wire:loading.attr="disabled"
                                wire:target="deleteResult({{ $result->id }})"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 00-.53.898l.348 3.48a.75.75 0 00.898.53c.127-.023.255-.044.384-.064L6 8.118v7.63A2.75 2.75 0 008.75 18.5h2.5A2.75 2.75 0 0014 15.748V8.118l.365.064a.75.75 0 00.898-.53l.348-3.48a.75.75 0 00-.53-.898 41.51 41.51 0 00-2.365-.298V3.75A2.75 2.75 0 0011.25 1h-2.5zM8.75 3.25a1.25 1.25 0 011.25-1.25h2.5a1.25 1.25 0 011.25 1.25v.443a41.51 41.51 0 00-5 0V3.25zm-3.62 3.54l-.262 2.622a41.51 41.51 0 003.882 0l-.262-2.622a42.28 42.28 0 00-3.358 0zm11.24 0a42.28 42.28 0 00-3.358 0l-.262 2.622a41.51 41.51 0 003.882 0l-.262-2.622zM9.75 8.25v6.498a1.25 1.25 0 001.25 1.25h2.5a1.25 1.25 0 001.25-1.25V8.25a.75.75 0 00-1.5 0v6.498a.25.25 0 01-.25.25h-2.5a.25.25 0 01-.25-.25V8.25a.75.75 0 00-1.5 0z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </li>
                @empty
                    <li class="seo-prompt-test-history__empty">
                        Chưa có lần chạy nào.<br>Bấm «Chạy thử» để bắt đầu.
                    </li>
                @endforelse
            </ul>
        </aside>
    </div>

    <style>
        .seo-prompt-test-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1.5rem;
            max-width: 100%;
        }

        @media (min-width: 1024px) {
            .seo-prompt-test-layout {
                grid-template-columns: minmax(0, 1fr) minmax(260px, 300px);
                align-items: start;
            }
        }

        .seo-history-row {
            display: flex;
            align-items: stretch;
            gap: 0.375rem;
            min-width: 0;
        }

        .seo-history-row .seo-history-card {
            flex: 1 1 auto;
            min-width: 0;
        }

        .seo-history-delete {
            flex: 0 0 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            align-self: stretch;
            padding: 0;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #dc2626;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .seo-history-delete svg {
            width: 1rem;
            height: 1rem;
        }

        .seo-history-delete:hover {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #b91c1c;
        }

        .seo-history-delete:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .dark .seo-history-delete {
            background: #1f2937;
            border-color: #4b5563;
            color: #f87171;
        }

        .dark .seo-history-delete:hover {
            background: rgba(220, 38, 38, 0.15);
            border-color: #dc2626;
        }

        .seo-prompt-test-sidebar {
            max-height: calc(100vh - 10rem);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 0.75rem;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
        }

        .dark .seo-prompt-test-sidebar {
            background: #111827;
            border-color: #374151;
        }

        .seo-prompt-test-sidebar__head {
            flex-shrink: 0;
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .dark .seo-prompt-test-sidebar__head {
            border-bottom-color: #374151;
        }

        .seo-prompt-test-sidebar__title {
            margin: 0;
            font-size: 0.875rem;
            font-weight: 600;
            color: #111827;
        }

        .dark .seo-prompt-test-sidebar__title {
            color: #f9fafb;
        }

        .seo-prompt-test-sidebar__meta {
            margin: 0.25rem 0 0;
            font-size: 0.75rem;
            color: #6b7280;
        }

        .seo-prompt-test-history {
            list-style: none;
            margin: 0;
            padding: 0.5rem;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .seo-prompt-test-history__item {
            margin: 0;
            min-width: 0;
        }

        .seo-prompt-test-history__empty {
            padding: 1.5rem 1rem;
            text-align: center;
            font-size: 0.75rem;
            line-height: 1.5;
            color: #6b7280;
        }

        .seo-history-card {
            display: block;
            width: 100%;
            box-sizing: border-box;
            text-align: left;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background: #fafafa;
            padding: 0.625rem 0.75rem;
            cursor: pointer;
            transition: box-shadow 0.15s ease, border-color 0.15s ease, background 0.15s ease;
        }

        .dark .seo-history-card {
            background: #1f2937;
            border-color: #374151;
        }

        .seo-history-card:hover {
            border-color: #d1d5db;
            background: #f3f4f6;
        }

        .dark .seo-history-card:hover {
            background: #374151;
        }

        .seo-history-card--completed {
            border-left: 3px solid #16a34a;
        }

        .seo-history-card--failed {
            border-left: 3px solid #dc2626;
        }

        .seo-history-card--pending {
            border-left: 3px solid #9ca3af;
        }

        .seo-history-card.is-active.seo-history-card--completed {
            background: #f0fdf4;
            border-color: #86efac;
            box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.2);
        }

        .seo-history-card.is-active.seo-history-card--failed {
            background: #fef2f2;
            border-color: #fca5a5;
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
        }

        .dark .seo-history-card.is-active.seo-history-card--completed {
            background: rgba(22, 163, 74, 0.12);
            border-color: #16a34a;
        }

        .dark .seo-history-card.is-active.seo-history-card--failed {
            background: rgba(220, 38, 38, 0.12);
            border-color: #dc2626;
        }

        .seo-history-card__grid {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            width: 100%;
            min-width: 0;
        }

        .seo-history-card__summary {
            display: block;
            width: 100%;
            font-size: 0.8125rem;
            font-weight: 600;
            line-height: 1.4;
            color: #111827;
            word-break: break-word;
        }

        .dark .seo-history-card__summary {
            color: #f3f4f6;
        }

        .seo-history-card__meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            min-width: 0;
        }

        .seo-history-card__time {
            font-size: 0.6875rem;
            font-weight: 500;
            color: #6b7280;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .dark .seo-history-card__time {
            color: #9ca3af;
        }

        .seo-history-card__tokens {
            font-weight: 500;
            color: #6b7280;
            white-space: nowrap;
        }

        .dark .seo-history-card__tokens {
            color: #9ca3af;
        }

        .seo-history-card__badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            flex-shrink: 0;
            margin-left: auto;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.625rem;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .seo-history-card__badge--completed {
            background: #dcfce7;
            color: #15803d;
        }

        .seo-history-card__badge--failed {
            background: #fee2e2;
            color: #b91c1c;
        }

        .seo-history-card__badge--pending {
            background: #f3f4f6;
            color: #6b7280;
        }

        .dark .seo-history-card__badge--completed {
            background: rgba(22, 163, 74, 0.25);
            color: #86efac;
        }

        .dark .seo-history-card__badge--failed {
            background: rgba(220, 38, 38, 0.25);
            color: #fca5a5;
        }

        .seo-history-card__icon {
            width: 0.875rem;
            height: 0.875rem;
            flex-shrink: 0;
        }

        .seo-prompt-test-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow: hidden;
        }

        .seo-prompt-test-pre-wrap {
            max-height: min(28rem, 55vh);
            overflow: auto;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
        }

        .dark .seo-prompt-test-pre-wrap {
            border-color: #4b5563;
        }

        .seo-prompt-test-pre {
            margin: 0;
            padding: 1rem;
            font-size: 0.875rem;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-word;
            background-color: #f3f4f6;
            color: #111827;
        }

        .dark .seo-prompt-test-pre {
            background-color: #1f2937;
            color: #f3f4f6;
        }

        .seo-prompt-test-publish {
            position: relative;
        }

        .seo-prompt-test-publish-menu {
            margin-top: 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 0.5rem;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            background: #fff;
            box-shadow: 0 4px 12px rgb(0 0 0 / 0.08);
        }

        .dark .seo-prompt-test-publish-menu {
            border-color: #4b5563;
            background: #111827;
        }

        .seo-prompt-test-publish-menu__item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.125rem;
            width: 100%;
            text-align: left;
            padding: 0.625rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            color: #111827;
            transition: background-color 0.15s;
        }

        .seo-prompt-test-publish-menu__item:hover {
            background-color: #f3f4f6;
        }

        .dark .seo-prompt-test-publish-menu__item {
            color: #f3f4f6;
        }

        .dark .seo-prompt-test-publish-menu__item:hover {
            background-color: #1f2937;
        }

        .seo-prompt-test-publish-menu__title {
            font-weight: 600;
        }

        .seo-prompt-test-publish-menu__desc {
            font-size: 0.75rem;
            line-height: 1.35;
            color: #6b7280;
        }

        .dark .seo-prompt-test-publish-menu__desc {
            color: #9ca3af;
        }

        .seo-prompt-test-publish-menu__desc code {
            font-size: 0.7em;
        }
    </style>
</x-filament-panels::page>
