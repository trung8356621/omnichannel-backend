<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'overview'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Tổng quan') }}</h1>
                    <p>{{ __('Cài đặt chung và trạng thái model AI (đồng bộ, ưu tiên, quota).') }}</p>
                </header>

                <section class="seo-ai-models-panel">
                    <div class="seo-ai-models-panel__head">
                        <div>
                            <h2 class="seo-ai-models-panel__title">Trạng thái model AI</h2>
                            <p class="seo-ai-models-panel__meta">
                                {{ $aiModelsOverview['total_models'] ?? 0 }} model
                                @if (filled($aiModelsOverview['last_synced_at'] ?? null))
                                    · Cập nhật gần nhất: {{ $aiModelsOverview['last_synced_at'] }}
                                @endif
                            </p>
                        </div>
                        <x-filament::button
                            type="button"
                            icon="heroicon-o-arrow-path"
                            wire:click="syncAllAiModels"
                            wire:loading.attr="disabled"
                            wire:target="syncAllAiModels"
                        >
                            <span wire:loading.remove wire:target="syncAllAiModels">Đồng bộ tất cả</span>
                            <span wire:loading wire:target="syncAllAiModels">Đang đồng bộ…</span>
                        </x-filament::button>
                    </div>

                    @forelse ($aiModelsOverview['connections'] ?? [] as $connection)
                        <div class="seo-ai-connection-block" wire:key="ai-conn-{{ $connection['id'] }}">
                            <div class="seo-ai-connection-block__head">
                                <div>
                                    <h3 class="seo-ai-connection-block__name">{{ $connection['name'] }}</h3>
                                    <p class="seo-ai-connection-block__meta">
                                        {{ strtoupper((string) $connection['provider']) }}
                                        · {{ $connection['model_count'] }} model
                                        · Kết nối: <span class="seo-ai-status seo-ai-status--{{ $connection['status'] }}">{{ $connection['status'] }}</span>
                                    </p>
                                </div>
                                <div class="seo-ai-connection-block__actions">
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        color="gray"
                                        tag="a"
                                        :href="$this->aiConnectionEditUrl($connection['id'])"
                                    >
                                        Sửa kết nối
                                    </x-filament::button>
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        icon="heroicon-o-arrow-path"
                                        wire:click="syncConnectionAiModels({{ $connection['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="syncConnectionAiModels({{ $connection['id'] }})"
                                    >
                                        Đồng bộ
                                    </x-filament::button>
                                </div>
                            </div>

                            @if (($connection['models'] ?? []) === [])
                                <p class="seo-ai-models-empty">
                                    Chưa có model trong <code>seo_ai_models</code>. Bấm «Đồng bộ» — Imagen / Nano Banana được seed từ catalog nội bộ (API Google thường không liệt kê Imagen).
                                </p>
                            @else
                                <div class="seo-ai-models-table-wrap">
                                    <table class="seo-ai-models-table">
                                        <thead>
                                            <tr>
                                                <th>Nhóm đại diện</th>
                                                <th>Model API (raw)</th>
                                                <th>Ưu tiên</th>
                                                <th>Trạng thái</th>
                                                <th>Cập nhật</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($connection['models'] as $model)
                                                <tr wire:key="ai-model-{{ $model['id'] }}">
                                                    <td>
                                                        <span class="seo-ai-models-table__cat">{{ $model['category_label'] }}</span>
                                                        <span class="seo-ai-models-table__sub">{{ $model['display_name'] }}</span>
                                                    </td>
                                                    <td><code>{{ $model['raw_model_name'] }}</code></td>
                                                    <td>{{ $model['priority'] }}</td>
                                                    <td>
                                                        <span class="seo-ai-status seo-ai-status--{{ $model['status'] }}">
                                                            {{ $model['status'] }}
                                                        </span>
                                                        @if (filled($model['last_error'] ?? null))
                                                            <span class="seo-ai-models-table__err" title="{{ $model['last_error'] }}">Lỗi quota</span>
                                                        @endif
                                                    </td>
                                                    <td class="seo-ai-models-table__time">{{ $model['updated_at'] ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="seo-ai-models-empty">
                            Chưa có kết nối AI. Thêm tại <a href="{{ \App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource::getUrl() }}" class="text-primary-600 underline">Cấu hình AI</a>.
                        </p>
                    @endforelse
                </section>

                <form wire:submit="saveOverviewSettings" class="max-w-3xl space-y-6 mt-8">
                    {{ $this->form }}

                    <div class="flex justify-end">
                        <x-filament::button type="submit" icon="heroicon-o-check">
                            {{ __('Lưu cấu hình FAQ') }}
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
