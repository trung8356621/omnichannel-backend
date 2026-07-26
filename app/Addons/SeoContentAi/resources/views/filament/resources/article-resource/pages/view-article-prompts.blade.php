<x-filament-panels::page>
    @vite('app/Addons/SeoContentAi/resources/css/project-run-step.css')

    @php
        $groups = $this->getRunGroups();
        $articleId = $this->getArticleId();
        $articleEditUrl = $this->getArticleEditUrl();
        $articleTitle = trim((string) ($this->articleRecord?->title ?? ''));
    @endphp

    <div class="seo-run-history-page">
        <section class="seo-run-history-summary">
            <div>
                <p class="seo-run-history-summary__eyebrow">ARTICLE #{{ $articleId }}</p>
                <h2 class="seo-run-history-summary__title">
                    {{ $articleTitle !== '' ? $articleTitle : 'Bài viết' }}
                </h2>
                <p class="seo-run-history-summary__description">
                    Tổng hợp mọi prompt AI đã lưu theo bài viết này (không phụ thuộc content project).
                </p>
            </div>

            @if ($articleEditUrl !== null)
                <x-filament::button
                    tag="a"
                    :href="$articleEditUrl"
                    icon="heroicon-o-pencil-square"
                    target="_blank"
                >
                    Chỉnh sửa bài viết
                </x-filament::button>
            @endif
        </section>

        @forelse ($groups as $group)
            @php
                $runId = $group['run_id'] ?? null;
                $projectName = trim((string) ($group['project_name'] ?? ''));
                $ranAt = $group['ran_at'] ?? null;
                $prompts = is_array($group['prompts'] ?? null) ? $group['prompts'] : [];
            @endphp

            <section class="seo-run-history-group">
                <header class="seo-run-history-group__header">
                    <div>
                        <p class="seo-run-history-group__eyebrow">
                            {{ $runId ? 'RUN #'.$runId : 'PROMPTS' }}
                            @if ($projectName !== '')
                                · {{ $projectName }}
                            @endif
                        </p>
                        <h2 class="seo-run-history-group__title">
                            {{ count($prompts) }} prompt AI
                        </h2>
                    </div>

                    <div class="seo-run-history-group__meta">
                        @if ($ranAt)
                            <span>{{ $ranAt->format('d/m/Y H:i') }}</span>
                        @endif
                        @if (filled($group['status'] ?? null))
                            <span class="seo-run-history-status">
                                {{ strtoupper((string) $group['status']) }}
                            </span>
                        @endif
                    </div>
                </header>

                <div class="seo-run-history-items">
                    @forelse ($prompts as $index => $promptItem)
                        @php
                            $promptType = trim((string) ($promptItem['type'] ?? 'Prompt AI'));
                            $status = trim((string) ($promptItem['status'] ?? ''));
                            $renderModel = trim((string) ($promptItem['render_model'] ?? ''));
                            $plannerModel = trim((string) ($promptItem['planner_model'] ?? ''));
                            $model = trim((string) ($promptItem['model'] ?? ''));
                            $promptText = trim((string) ($promptItem['prompt'] ?? ''));
                            $resultText = trim((string) ($promptItem['result'] ?? ''));
                            $sourceBadge = trim((string) ($promptItem['source_badge'] ?? ''));
                            $ownerBadge = trim((string) ($promptItem['owner_badge'] ?? ''));
                            $promptName = trim((string) ($promptItem['prompt_name'] ?? ''));
                            $workflowNode = trim((string) ($promptItem['workflow_node_title'] ?? ''));
                            $executionTypeLabel = trim((string) ($promptItem['execution_type_label'] ?? ''));
                            $statusLabel = trim((string) ($promptItem['status_label'] ?? $status));
                            $sourceRunId = $promptItem['source_run_id'] ?? null;
                            $sourceRunItemId = $promptItem['source_run_item_id'] ?? null;
                            $articleLengthMeta = $promptItem['article_length'] ?? null;
                        @endphp

                        <div
                            class="seo-run-history-item"
                            x-data="{ open: {{ $index === 0 ? 'true' : 'false' }} }"
                        >
                            <button
                                type="button"
                                class="seo-run-history-item__toggle"
                                x-on:click="open = ! open"
                                x-bind:aria-expanded="open"
                            >
                                <span class="seo-run-history-item__identity">
                                    <span class="seo-run-history-item__index">{{ $index + 1 }}</span>
                                    <span>
                                        <span class="seo-run-history-item__type">{{ $promptType }}</span>
                                        @if ($executionTypeLabel !== '')
                                            <span class="seo-run-history-item__model" title="Execution type">{{ $executionTypeLabel }}</span>
                                        @endif
                                        @if ($statusLabel !== '')
                                            <span class="seo-run-history-item__model" title="Status">{{ $statusLabel }}</span>
                                        @endif
                                        @if ($sourceBadge !== '')
                                            <span class="seo-run-history-item__model" title="Article writing source">{{ $sourceBadge }}</span>
                                        @endif
                                        @if ($ownerBadge !== '')
                                            <span class="seo-run-history-item__model" title="Prompt owner">{{ $ownerBadge }}</span>
                                        @endif
                                        @if ($promptName !== '')
                                            <span class="seo-run-history-item__model" title="Prompt">Prompt: {{ $promptName }}</span>
                                        @endif
                                        @if ($workflowNode !== '' && $ownerBadge === 'Owner: Workflow')
                                            <span class="seo-run-history-item__model" title="Workflow node">Node: {{ $workflowNode }}</span>
                                        @endif
                                        @if ($sourceRunId || $sourceRunItemId)
                                            <span class="seo-run-history-item__model" title="Source artifact">
                                                Artifact:
                                                @if ($sourceRunId) run {{ $sourceRunId }}@endif
                                                @if ($sourceRunItemId) / item {{ $sourceRunItemId }}@endif
                                            </span>
                                        @endif
                                        @if ($articleLengthMeta !== null && $articleLengthMeta !== '')
                                            <span class="seo-run-history-item__model" title="Article length">Length: {{ $articleLengthMeta }}</span>
                                        @endif
                                        @if ($renderModel !== '')
                                            <span class="seo-run-history-item__model" title="Render model">{{ $renderModel }}</span>
                                        @elseif ($model !== '')
                                            <span class="seo-run-history-item__model">{{ $model }}</span>
                                        @endif
                                        @if ($plannerModel !== '' && $plannerModel !== $renderModel)
                                            <span class="seo-run-history-item__model seo-run-history-item__model--planner" title="Planner model">planner: {{ $plannerModel }}</span>
                                        @endif
                                    </span>
                                </span>

                                <span class="seo-run-history-item__actions">
                                    @if ($status !== '')
                                        <span class="seo-run-history-status {{ $status === 'failed' ? 'is-failed' : '' }}">
                                            {{ strtoupper($status) }}
                                        </span>
                                    @endif
                                    <svg
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-hidden="true"
                                        x-bind:class="{ 'rotate-180': open }"
                                    >
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.512a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            </button>

                            <div
                                class="seo-run-history-item__content"
                                x-cloak
                                x-show="open"
                                x-collapse
                            >
                                @if (filled($promptItem['message'] ?? null))
                                    <p class="seo-run-history-item__message">
                                        {{ $promptItem['message'] }}
                                    </p>
                                @endif

                                <div class="seo-run-history-columns">
                                    <x-seo-content-ai::ai-result label="Prompt" max-height="32rem">
                                        {{ $promptText !== '' ? $promptText : 'Không còn dữ liệu prompt cho lần chạy này.' }}
                                    </x-seo-content-ai::ai-result>

                                    <x-seo-content-ai::ai-result label="Kết quả" max-height="32rem">
                                        {{ $resultText !== '' ? $resultText : 'Không có kết quả được lưu.' }}
                                    </x-seo-content-ai::ai-result>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="seo-run-step-empty">
                            Chưa có prompt nào trong nhóm này.
                        </div>
                    @endforelse
                </div>
            </section>
        @empty
            <section class="seo-run-step-empty">
                Bài viết này chưa có lịch sử prompt AI được lưu.
            </section>
        @endforelse
    </div>
</x-filament-panels::page>

