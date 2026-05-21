<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\TaskResource\Pages\Concerns;

use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Support\AiModelCatalog;

trait InteractsWithTaskWorkflow
{
    /**
     * @return list<array{id: string, name: string, defaultModel: string, models: array<string, string>, tasks: list<array{id: string, name: string}>}>
     */
    public function getPromptsForBuilder(): array
    {
        return SeoPrompt::query()
            ->where('is_active', true)
            ->with(['parts' => fn ($q) => $q->orderBy('position'), 'aiConnection'])
            ->orderBy('name')
            ->get()
            ->map(function (SeoPrompt $prompt): array {
                $tasks = $prompt->parts
                    ->where('role', 'task')
                    ->map(fn ($part) => [
                        'id' => 'part_' . $part->id,
                        'name' => (string) ($part->name ?: 'Nhiệm vụ'),
                    ])
                    ->values()
                    ->all();

                if ($tasks === []) {
                    $tasks = [['id' => 'task_main', 'name' => 'Toàn bộ prompt']];
                }

                return [
                    'id' => (string) $prompt->id,
                    'name' => (string) $prompt->name,
                    'defaultModel' => AiModelCatalog::defaultForConnection($prompt->aiConnection),
                    'models' => AiModelCatalog::optionsForConnection($prompt->aiConnection),
                    'tasks' => $tasks,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{nodes: array<int, mixed>, edges: array<int, mixed>}
     */
    protected function normalizeFlowPayload(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $this->normalizeFlowPayload($decoded) : ['nodes' => [], 'edges' => []];
        }

        if (! is_array($raw)) {
            return ['nodes' => [], 'edges' => []];
        }

        if (isset($raw['nodes']) || isset($raw['edges'])) {
            return [
                'nodes' => is_array($raw['nodes'] ?? null) ? $raw['nodes'] : [],
                'edges' => is_array($raw['edges'] ?? null) ? $raw['edges'] : [],
            ];
        }

        return ['nodes' => [], 'edges' => []];
    }

    /**
     * @param  array{name?: string, flow_data?: mixed}  $data
     */
    public function saveFlow(array $data): void
    {
        $this->persistTaskFlow(
            trim((string) ($data['name'] ?? '')),
            $this->normalizeFlowPayload($data['flow_data'] ?? null),
        );
    }

    abstract protected function persistTaskFlow(string $taskName, array $flowData): void;
}
