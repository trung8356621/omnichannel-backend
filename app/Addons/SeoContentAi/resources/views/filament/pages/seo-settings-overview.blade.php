<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'overview'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Overview') }}</h1>
                    <p>{{ __('General settings and AI model status (sync, priority, quota).') }}</p>
                </header>

                <section class="seo-ai-models-panel">
                    <div class="seo-ai-models-panel__head">
                        <div>
                            <h2 class="seo-ai-models-panel__title">AI model status</h2>
                            <p class="seo-ai-models-panel__meta">
                                {{ $aiModelsOverview['total_models'] ?? 0 }} model
                                @if (filled($aiModelsOverview['last_synced_at'] ?? null))
                                    · Last updated: {{ $aiModelsOverview['last_synced_at'] }}
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
                            <span wire:loading.remove wire:target="syncAllAiModels">Sync all</span>
                            <span wire:loading wire:target="syncAllAiModels">Syncing...</span>
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
                                        Edit connection
                                    </x-filament::button>
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        icon="heroicon-o-arrow-path"
                                        wire:click="syncConnectionAiModels({{ $connection['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="syncConnectionAiModels({{ $connection['id'] }})"
                                    >
                                        Sync
                                    </x-filament::button>
                                </div>
                            </div>

                            @php
                                $models = is_array($connection['models'] ?? null) ? $connection['models'] : [];
                                $modelPreviewLimit = 50;
                                $visibleModels = array_slice($models, 0, $modelPreviewLimit);
                            @endphp

                            @if ($models === [])
                                <p class="seo-ai-models-empty">
                                    No models in <code>seo_ai_models</code> yet. Click "Sync" - Imagen / Nano Banana are seeded from internal catalog (Google API often does not list Imagen).
                                </p>
                            @else
                                <div class="seo-ai-models-table-wrap">
                                    <table class="seo-ai-models-table">
                                        <thead>
                                            <tr>
                                                <th>Representative group</th>
                                                <th>API model (raw)</th>
                                                <th>Priority</th>
                                                <th>Status</th>
                                                <th>Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($visibleModels as $model)
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
                                                            <span class="seo-ai-models-table__err" title="{{ $model['last_error'] }}">Quota error</span>
                                                        @endif
                                                    </td>
                                                    <td class="seo-ai-models-table__time">{{ $model['updated_at'] ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if (count($models) > $modelPreviewLimit)
                                    <p class="seo-ai-models-empty mt-2">
                                        Showing {{ $modelPreviewLimit }}/{{ count($models) }} models for performance.
                                        Open <a href="{{ $this->aiConnectionEditUrl($connection['id']) }}" class="text-primary-600 underline">Edit connection</a> to manage all.
                                    </p>
                                @endif
                            @endif
                        </div>
                    @empty
                        <p class="seo-ai-models-empty">
                            No AI connections yet. Add one in <a href="{{ \App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource::getUrl() }}" class="text-primary-600 underline">AI settings</a>.
                        </p>
                    @endforelse
                </section>

                <section class="seo-ai-models-panel mt-6">
                    <form wire:submit="saveTeamChatSettings">
                        {{ $this->teamChatForm }}

                        <div class="mt-4">
                            <x-seo-content-ai::form-save-button
                                target="saveTeamChatSettings"
                                :label="__('seo-content-ai::filament.settings_overview.team_chat_save')"
                            />
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </x-filament-panels::page>
</div>
