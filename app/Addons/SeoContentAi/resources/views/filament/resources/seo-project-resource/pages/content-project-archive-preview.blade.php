@php
    use App\Addons\SeoContentAi\Models\SeoProjectArchiveItem;

    $summary = $this->getHeaderSummary();
    $items = $this->archive?->items ?? collect();
    $selectedItem = $this->selectedItem;
    $selectedDetails = $selectedItem instanceof SeoProjectArchiveItem ? $this->buildItemDetails($selectedItem) : null;

    $month = (int) ($summary['month'] ?? 0);
    $year = (int) ($summary['year'] ?? 0);
    $period = ($month > 0 && $year > 0) ? sprintf('%02d/%d', $month, $year) : '—';
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                {{ e((string) ($summary['project_name'] ?? '')) ?: '—' }}
            </h2>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.article_list.domain') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($summary['domain'] ?? '')) ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.month') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ e($period) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.owner') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($summary['owner'] ?? '')) ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_archived_at') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ \App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource::formatTaskTimestamp($summary['archived_at'] ?? null) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_total') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ (int) ($summary['total_articles'] ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_completed') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ (int) ($summary['completed_articles'] ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_approved') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ (int) ($summary['approved_articles'] ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_synced') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ (int) ($summary['synced_articles'] ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_avg_seo') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">
                        @if (($summary['average_seo_score'] ?? null) !== null)
                            {{ number_format((float) $summary['average_seo_score'], 2) }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_archived_by') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($summary['archived_by'] ?? '')) ?: '—' }}</dd>
                </div>
            </dl>

            @if (trim((string) ($summary['note'] ?? '')) !== '')
                <div class="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-800/60 dark:text-gray-200">
                    <span class="font-medium">{{ __('seo-content-ai::filament.projects.archive_note') }}:</span>
                    {{ e((string) $summary['note']) }}
                </div>
            @endif
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">#</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_title') }}</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_preview_col_keyword') }}</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_status') }}</th>
                        <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_col_avg_seo') }}</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.projects.archive_preview_col_sync') }}</th>
                        <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($items as $item)
                        @php
                            if (! $item instanceof SeoProjectArchiveItem) {
                                continue;
                            }
                            $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
                            $title = trim((string) ($snapshot['title'] ?? $item->article?->title ?? ''));
                            $keyword = trim((string) ($snapshot['primary_keyword'] ?? ''));
                            $status = trim((string) ($snapshot['status'] ?? $item->task?->status ?? ''));
                            $seoScore = $snapshot['seo_score'] ?? $item->article?->seo_score;
                            $syncStatus = trim((string) ($snapshot['sync_status'] ?? $item->article?->wp_sync_status ?? ''));
                        @endphp
                        <tr wire:key="archive-preview-item-{{ $item->id }}">
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ (int) ($item->position ?? 0) ?: $loop->iteration }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $title !== '' ? e($title) : '—' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $keyword !== '' ? e($keyword) : '—' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $status !== '' ? e($status) : '—' }}</td>
                            <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ $seoScore !== null ? number_format((float) $seoScore, 2) : '—' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $syncStatus !== '' ? e($syncStatus) : '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-primary-700 ring-1 ring-primary-300 hover:bg-primary-50 dark:text-primary-300 dark:ring-primary-500/40 dark:hover:bg-primary-500/10"
                                    x-on:click="$wire.openItem({{ $item->id }})"
                                >
                                    {{ __('seo-content-ai::filament.projects.archive_preview_item') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.projects.archive_preview_empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <template x-teleport="body">
        <div
            x-show="$wire.selectedItemId"
            x-cloak
            x-on:keydown.escape.window="$wire.closeItem()"
            class="fixed inset-0 z-[80] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
        >
            <div
                class="absolute inset-0 bg-gray-950/50"
                x-on:click="$wire.closeItem()"
            ></div>

            <div class="relative z-10 flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                            {{ __('seo-content-ai::filament.projects.archive_preview_item_heading') }}
                        </h3>
                        @if (is_array($selectedDetails))
                            <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ e((string) ($selectedDetails['title'] ?? '')) }}</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                        x-on:click="$wire.closeItem()"
                    >
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="overflow-y-auto px-5 py-4">
                    @if (is_array($selectedDetails))
                        <dl class="grid gap-4 text-sm sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_title') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['title'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_slug') }}</dt>
                                <dd class="mt-1 break-all text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['slug'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_keyword') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['keyword'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_meta_title') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['meta_title'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_meta_description') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['meta_description'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_outline') }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['outline_meta'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_status') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['task_status'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_review_status') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['approved_status'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_avg_seo') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">
                                    @if (($selectedDetails['seo_score'] ?? null) !== null)
                                        {{ number_format((float) $selectedDetails['seo_score'], 2) }}
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_images') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ (int) ($selectedDetails['image_count'] ?? 0) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_sync') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['sync_status'] ?? '')) ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_wp_post') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ ($selectedDetails['wordpress_post_id'] ?? null) ? (int) $selectedDetails['wordpress_post_id'] : '—' }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_wp_url') }}</dt>
                                <dd class="mt-1 break-all text-gray-900 dark:text-white">
                                    @if (trim((string) ($selectedDetails['wordpress_url'] ?? '')) !== '')
                                        <a href="{{ e((string) $selectedDetails['wordpress_url']) }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">
                                            {{ e((string) $selectedDetails['wordpress_url']) }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                            @if (trim((string) ($selectedDetails['wp_sync_error'] ?? '')) !== '')
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-danger-600 dark:text-danger-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_wp_error') }}</dt>
                                    <dd class="mt-1 text-danger-700 dark:text-danger-300">{{ e((string) $selectedDetails['wp_sync_error']) }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_created_at') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['created_at'] ?? '—')) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_updated_at') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['updated_at'] ?? '—')) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.completed_at') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['completed_at'] ?? '—')) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_last_saved') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['last_saved_at'] ?? '—')) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_last_synced') }}</dt>
                                <dd class="mt-1 text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['last_synced_at'] ?? '—')) }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_body_excerpt') }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap text-gray-900 dark:text-white">{{ e((string) ($selectedDetails['body_excerpt'] ?? '')) ?: '—' }}</dd>
                            </div>
                        </dl>
                    @else
                        <div class="animate-pulse space-y-3">
                            <div class="h-4 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                            <div class="h-4 w-full rounded bg-gray-200 dark:bg-gray-700"></div>
                            <div class="h-4 w-5/6 rounded bg-gray-200 dark:bg-gray-700"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </template>
</x-filament-panels::page>
