<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Support\TaskTestContext;

final class TaskWorkflowTestRunner
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function run(SeoTask $task, TaskTestContext $context): array
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];

        if ($nodes === []) {
            throw new \InvalidArgumentException('Quy trình chưa có sơ đồ (flow). Mở Builder để thiết kế.');
        }

        $ordered = $this->orderedNodes($nodes, $edges);
        $steps = [];
        $variables = $context->variables;

        foreach ($ordered as $node) {
            $type = (string) ($node['type'] ?? '');
            $nodeId = (string) ($node['id'] ?? '');
            $title = (string) ($node['title'] ?? $type);

            if ($type === 'article') {
                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => 'ok',
                    'message' => $context->summary,
                ];

                continue;
            }

            if ($type === 'filter') {
                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => 'skipped',
                    'message' => 'Bỏ qua khi chạy thử (chưa hỗ trợ lọc).',
                ];

                continue;
            }

            if ($type === 'action') {
                $actionType = (string) ($node['data']['actionType'] ?? 'create_article');
                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => 'skipped',
                    'message' => 'Hành động: ' . $actionType . ' (chưa thực thi trong chạy thử).',
                ];

                continue;
            }

            if ($type === 'prompt') {
                $promptId = $node['data']['promptId'] ?? null;
                $prompt = $this->resolvePrompt($promptId);

                if ($prompt === null) {
                    $steps[] = [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => 'failed',
                        'message' => 'Không tìm thấy prompt #' . (string) $promptId,
                    ];

                    continue;
                }

                try {
                    $result = $this->promptRunner->run($prompt, $variables);
                    $output = trim((string) ($result->output_text ?? ''));

                    $steps[] = [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => $result->status === 'completed' ? 'completed' : 'failed',
                        'prompt_id' => $prompt->id,
                        'prompt_name' => (string) $prompt->name,
                        'output' => $output,
                        'result_id' => $result->id,
                        'message' => $result->status === 'completed'
                            ? 'Chạy prompt thành công.'
                            : (string) ($result->error_message ?? 'Prompt thất bại.'),
                    ];
                } catch (PromptRunException $exception) {
                    $steps[] = [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => 'failed',
                        'prompt_id' => $prompt->id,
                        'prompt_name' => (string) $prompt->name,
                        'message' => $exception->getMessage(),
                    ];
                }

                continue;
            }

            $steps[] = [
                'node_id' => $nodeId,
                'type' => $type,
                'title' => $title,
                'status' => 'skipped',
                'message' => 'Loại node không hỗ trợ: ' . $type,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function orderedNodes(array $nodes, array $edges): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $node;
            }
        }

        if ($byId === []) {
            return [];
        }

        $adjacency = [];
        $inDegree = array_fill_keys(array_keys($byId), 0);

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = (string) ($edge['sourceNode'] ?? '');
            $target = (string) ($edge['targetNode'] ?? '');
            if ($source === '' || $target === '' || ! isset($byId[$source], $byId[$target])) {
                continue;
            }
            $adjacency[$source][] = $target;
            $inDegree[$target] = ($inDegree[$target] ?? 0) + 1;
        }

        $starts = [];
        foreach ($byId as $id => $node) {
            if (($node['type'] ?? '') === 'article') {
                $starts[] = $id;
            }
        }

        if ($starts === []) {
            foreach ($inDegree as $id => $degree) {
                if ($degree === 0) {
                    $starts[] = $id;
                }
            }
        }

        if ($starts === []) {
            $starts[] = array_key_first($byId);
        }

        $queue = $starts;
        $visited = [];
        $ordered = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $ordered[] = $byId[$id];

            foreach ($adjacency[$id] ?? [] as $nextId) {
                if (! isset($visited[$nextId])) {
                    $queue[] = $nextId;
                }
            }
        }

        foreach ($byId as $id => $node) {
            if (! isset($visited[$id])) {
                $ordered[] = $node;
            }
        }

        return $ordered;
    }

    private function resolvePrompt(mixed $promptId): ?SeoPrompt
    {
        if ($promptId === null || $promptId === '') {
            return null;
        }

        if (is_numeric($promptId)) {
            return SeoPrompt::query()->where('is_active', true)->find((int) $promptId);
        }

        $idString = (string) $promptId;
        if (preg_match('/^p(\d+)$/', $idString, $matches)) {
            return SeoPrompt::query()->where('is_active', true)->find((int) $matches[1]);
        }

        return SeoPrompt::query()
            ->where('is_active', true)
            ->where('id', $idString)
            ->first();
    }
}
