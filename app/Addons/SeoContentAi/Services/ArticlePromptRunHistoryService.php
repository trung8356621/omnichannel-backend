<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPromptResultLink;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use Illuminate\Support\Collection;

final class ArticlePromptRunHistoryService
{
    /** @var list<string> */
    private const HIDDEN_SOURCES = [
        'workflow_run_backfill',
        'snapshot_backfill',
        'legacy_pivot_backfill',
    ];

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return list<array<string, mixed>>
     */
    public function build(SeoArticle $article, array $accessibleProjectIds): array
    {
        $articleId = (int) $article->getKey();

        $runs = SeoProjectRun::query()
            ->with('project:id,name')
            ->whereIn('project_id', $accessibleProjectIds)
            ->latest('id')
            ->get()
            ->map(function (SeoProjectRun $run) use ($articleId): ?array {
                $matchingItems = collect(is_array($run->items) ? $run->items : [])
                    ->filter(
                        fn (mixed $item): bool => is_array($item)
                            && (int) ($item['article_id'] ?? 0) === $articleId,
                    )
                    ->values();

                if ($matchingItems->isEmpty()) {
                    return null;
                }

                return [
                    'run' => $run,
                    'items' => $matchingItems,
                ];
            })
            ->filter()
            ->values();

        $accessibleRunIds = SeoProjectRun::query()
            ->whereIn('project_id', $accessibleProjectIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $linkedRows = SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->where(function ($query) use ($accessibleRunIds): void {
                $query->whereNull('project_run_id');
                if ($accessibleRunIds !== []) {
                    $query->orWhereIn('project_run_id', $accessibleRunIds);
                }
            })
            ->orderBy('id')
            ->get();

        $resultIds = $runs
            ->flatMap(fn (array $entry): Collection => $entry['items'])
            ->flatMap(
                fn (array $item): array => array_values(array_filter(
                    is_array($item['steps'] ?? null) ? $item['steps'] : [],
                    'is_array',
                )),
            )
            ->map(fn (array $step): int => (int) ($step['result_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        $attachedResults = $article->promptResults()
            ->with('prompt')
            ->orderBy('prompt_results.created_at')
            ->get();

        // Nhiều luồng editor (gallery image, quick review...) chỉ lưu article_id trong input_snapshot
        // mà không attach pivot seo_prompt_resultables, nên cần suy luận thêm từ JSON snapshot.
        $snapshotResults = PromptResult::query()
            ->with('prompt')
            ->where(function ($query) use ($articleId): void {
                $query
                    ->where('input_snapshot->article_id', (string) $articleId)
                    ->orWhere('input_snapshot->article_id', $articleId)
                    ->orWhere('input_snapshot->variables->article_id', (string) $articleId)
                    ->orWhere('input_snapshot->variables->article_id', $articleId);
            })
            ->orderBy('created_at')
            ->get();

        $resultIds = $resultIds
            ->merge($attachedResults->pluck('id')->map(static fn (mixed $id): int => (int) $id))
            ->merge($snapshotResults->pluck('id')->map(static fn (mixed $id): int => (int) $id))
            ->merge($linkedRows->pluck('prompt_result_id')->map(static fn (mixed $id): int => (int) $id))
            ->filter()
            ->unique()
            ->values();

        $results = PromptResult::query()
            ->with('prompt')
            ->whereIn('id', $resultIds)
            ->get()
            ->keyBy(fn (PromptResult $result): int => (int) $result->getKey());

        $seenResultIds = [];
        $groups = $runs
            ->map(function (array $entry) use ($results, $linkedRows, &$seenResultIds): array {
                /** @var SeoProjectRun $run */
                $run = $entry['run'];
                /** @var Collection<int, array<string, mixed>> $items */
                $items = $entry['items'];

                $prompts = $items
                    ->flatMap(function (array $item) use ($run, $results, &$seenResultIds): array {
                        $steps = array_values(array_filter(
                            is_array($item['steps'] ?? null) ? $item['steps'] : [],
                            'is_array',
                        ));

                        return collect($steps)
                            ->map(function (array $step, int $index) use ($item, $run, $results, &$seenResultIds): array {
                                $resultId = (int) ($step['result_id'] ?? 0);
                                $result = $resultId > 0 ? $results->get($resultId) : null;

                                if ($resultId > 0) {
                                    $seenResultIds[$resultId] = true;
                                }

                                return $this->normalizePromptItem(
                                    $step,
                                    $result instanceof PromptResult ? $result : null,
                                    (int) $run->id,
                                    (int) ($item['task_id'] ?? 0),
                                    $index,
                                );
                            })
                            ->all();
                    })
                    ->values()
                    ->all();

                $runLinkedPrompts = $linkedRows
                    ->filter(fn (SeoPromptResultLink $link): bool => (int) ($link->project_run_id ?? 0) === (int) $run->id)
                    ->map(function (SeoPromptResultLink $link) use ($run, $results, &$seenResultIds): ?array {
                        $source = trim((string) $link->source);
                        if (in_array($source, self::HIDDEN_SOURCES, true)) {
                            return null;
                        }

                        $resultId = (int) $link->prompt_result_id;
                        if ($resultId <= 0) {
                            return null;
                        }

                        if (isset($seenResultIds[$resultId])) {
                            return null;
                        }

                        $result = $results->get($resultId);
                        if (! $result instanceof PromptResult) {
                            return null;
                        }

                        $seenResultIds[$resultId] = true;

                        return $this->normalizePromptItem(
                            [
                                '_source' => $source,
                                'prompt_name' => (string) ($result->prompt?->name ?? ''),
                                'status' => (string) $result->status,
                                'output' => (string) ($result->output_text ?? ''),
                                'message' => (string) ($result->error_message ?? ''),
                            ],
                            $result,
                            (int) $run->id,
                            (int) ($link->project_task_id ?? 0),
                            0,
                        );
                    })
                    ->filter()
                    ->values()
                    ->all();

                $prompts = collect($prompts)
                    ->merge($runLinkedPrompts)
                    ->values()
                    ->all();

                return [
                    'id' => 'run-'.$run->id,
                    'run_id' => (int) $run->id,
                    'project_name' => trim((string) ($run->project?->name ?? '')),
                    'mode' => (string) $run->mode,
                    'status' => (string) $run->status,
                    'ran_at' => $run->started_at ?? $run->created_at,
                    'prompts' => $prompts,
                ];
            })
            ->values();

        $linkedResults = $linkedRows
            ->map(fn (SeoPromptResultLink $link): ?PromptResult => $results->get((int) $link->prompt_result_id))
            ->filter(fn (mixed $result): bool => $result instanceof PromptResult)
            ->values();

        $articleLinkedResults = $attachedResults
            ->merge($snapshotResults)
            ->merge($linkedResults)
            ->unique(fn (PromptResult $result): int => (int) $result->id)
            ->values();

        $orphanPrompts = $articleLinkedResults
            ->filter(fn (PromptResult $result): bool => ! isset($seenResultIds[(int) $result->id]))
            ->map(function (PromptResult $result): array {
                return $this->normalizePromptItem(
                    [
                        'prompt_name' => (string) ($result->prompt?->name ?? ''),
                        'status' => (string) $result->status,
                        'output' => (string) ($result->output_text ?? ''),
                        'message' => (string) ($result->error_message ?? ''),
                        'type' => (string) ($result->pivot?->type ?? ''),
                    ],
                    $result,
                    0,
                    0,
                    0,
                );
            })
            ->values()
            ->all();

        if ($orphanPrompts !== []) {
            $groups->push([
                'id' => 'article-prompts',
                'run_id' => null,
                'project_name' => '',
                'mode' => 'article',
                'status' => '',
                'ran_at' => $articleLinkedResults->max('created_at'),
                'prompts' => $orphanPrompts,
            ]);
        }

        return $groups
            ->sortByDesc(fn (array $group): int => $group['ran_at']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function normalizePromptItem(
        array $step,
        ?PromptResult $result,
        int $runId,
        int $taskId,
        int $index,
    ): array {
        $snapshot = is_array($result?->input_snapshot) ? $result->input_snapshot : [];
        $compiledPrompt = trim((string) ($snapshot['compiled_prompt'] ?? ''));
        $promptTemplate = trim((string) ($result?->prompt?->markdown_content ?? ''));
        $fallbackInput = trim((string) ($step['input_used'] ?? ''));

        $prompt = $compiledPrompt !== ''
            ? $compiledPrompt
            : ($promptTemplate !== '' ? $promptTemplate : $fallbackInput);

        $output = trim((string) ($result?->output_text ?? ''));
        if ($output === '') {
            $output = trim((string) ($step['output'] ?? ''));
        }

        $type = trim((string) ($step['type'] ?? ''));
        $source = trim((string) ($step['_source'] ?? ''));
        $name = trim((string) ($step['prompt_name'] ?? $step['title'] ?? $result?->prompt?->name ?? ''));
        $displayType = $this->resolveDisplayType($type, $source, $name);

        return [
            'key' => $result !== null
                ? 'result-'.$result->id
                : sprintf('run-%d-task-%d-step-%d', $runId, $taskId, $index),
            'result_id' => $result?->id,
            'prompt_id' => (int) ($step['prompt_id'] ?? $result?->prompt_id ?? 0),
            'type' => $displayType,
            'prompt_name' => $name,
            'prompt' => $prompt,
            'result' => $output,
            'status' => trim((string) ($step['status'] ?? $result?->status ?? '')),
            'message' => trim((string) ($step['message'] ?? $result?->error_message ?? '')),
            'model' => trim((string) ($step['ai_model'] ?? $snapshot['raw_model_used'] ?? '')),
            'ran_at' => $result?->started_at ?? $result?->created_at,
        ];
    }

    private function resolveDisplayType(string $type, string $source, string $name): string
    {
        if ($type !== '' && $type !== 'prompt') {
            return $type;
        }

        return match ($source) {
            'editor_media_generation' => 'Media AI',
            'quick_review_workflow' => 'Review AI',
            'workflow_run',
            'workflow_run_failed' => $name !== '' ? $name : 'Prompt AI',
            default => $name !== '' ? $name : 'Prompt AI',
        };
    }
}
