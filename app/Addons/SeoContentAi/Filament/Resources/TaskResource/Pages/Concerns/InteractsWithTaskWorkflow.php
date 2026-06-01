<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\TaskResource\Pages\Concerns;

use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Services\WorkflowTagExtractorService;
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
            ->with('aiConnection')
            ->orderBy('name')
            ->get()
            ->map(function (SeoPrompt $prompt): array {
                $tasks = $prompt->resolvedParts()
                    ->where('role', 'task')
                    ->map(static fn ($part, int $index): array => [
                        'id' => 'part_' . $prompt->id . '_' . (int) ($part->position ?? $index),
                        'name' => (string) ($part->name ?: 'Task'),
                    ])
                    ->values()
                    ->all();

                if ($tasks === []) {
                    $tasks = [['id' => 'task_main', 'name' => 'Whole prompt']];
                }

                $detectedTags = is_array($prompt->settings['detected_tags'] ?? null)
                    ? $prompt->settings['detected_tags']
                    : app(WorkflowTagExtractorService::class)
                        ->detectTagsFromPromptTemplate((string) ($prompt->markdown_content ?? ''));
                $detectedTags = array_values(array_filter($detectedTags, static fn (mixed $row): bool => is_array($row)));
                if ($detectedTags !== []) {
                    $tasks = array_map(
                        static fn (array $tag): array => [
                            'id' => (string) ($tag['id'] ?? ''),
                            'name' => (string) ($tag['label'] ?? ''),
                            'key' => (string) ($tag['key'] ?? ''),
                        ],
                        $detectedTags,
                    );
                }

                return [
                    'id' => (string) $prompt->id,
                    'name' => (string) $prompt->name,
                    'defaultModel' => AiModelCatalog::defaultForConnection($prompt->aiConnection),
                    'models' => AiModelCatalog::optionsForConnection($prompt->aiConnection),
                    'tasks' => $tasks,
                    'detected_tags' => $detectedTags,
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
