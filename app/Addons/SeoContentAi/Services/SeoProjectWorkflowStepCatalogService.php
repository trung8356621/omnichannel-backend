<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Support\ImageToolType;

/**
 * Danh sách bước prompt có thể «Chạy lại» từ workflow SeoTask của project.
 */
final class SeoProjectWorkflowStepCatalogService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return list<array{
     *     node_id: string,
     *     title: string,
     *     label: string,
     *     kind: string,
     *     prompt_id: int|null,
     *     depends_on_kinds: list<string>
     * }>
     */
    public function listRerunnableSteps(SeoProjectTask $projectTask): array
    {
        $seoTask = $this->resolveSeoTask($projectTask);
        if (! $seoTask instanceof SeoTask) {
            return [];
        }

        $flow = is_array($seoTask->flow_data) ? $seoTask->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $steps = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            if ($type !== 'prompt') {
                continue;
            }

            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }

            $title = trim((string) ($node['title'] ?? ''));
            if ($title === '') {
                $title = 'Prompt';
            }

            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : null;
            $prompt = $promptId !== null && $promptId > 0
                ? SeoPrompt::query()->find($promptId)
                : null;

            $kind = $this->detectKind($node, $prompt);
            $label = $this->labelForKind($kind, $title);

            $steps[] = [
                'node_id' => $nodeId,
                'title' => $title,
                'label' => $label,
                'kind' => $kind,
                'prompt_id' => $prompt instanceof SeoPrompt ? (int) $prompt->id : $promptId,
                'depends_on_kinds' => $kind === 'content' ? ['outline'] : [],
            ];
        }

        return $steps;
    }

    /**
     * @param  list<string>  $nodeIds
     * @return list<string>
     */
    public function orderNodeIdsByDependency(SeoProjectTask $projectTask, array $nodeIds): array
    {
        $catalog = $this->listRerunnableSteps($projectTask);
        $byId = [];
        foreach ($catalog as $step) {
            $byId[$step['node_id']] = $step;
        }

        $selected = [];
        foreach ($nodeIds as $nodeId) {
            $id = trim((string) $nodeId);
            if ($id === '' || ! isset($byId[$id])) {
                continue;
            }
            $selected[] = $byId[$id];
        }

        usort($selected, static function (array $left, array $right): int {
            $leftRank = $left['kind'] === 'outline' ? 0 : ($left['kind'] === 'content' ? 1 : 2);
            $rightRank = $right['kind'] === 'outline' ? 0 : ($right['kind'] === 'content' ? 1 : 2);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) $left['node_id'], (string) $right['node_id']);
        });

        return array_values(array_map(
            static fn (array $step): string => (string) $step['node_id'],
            $selected,
        ));
    }

    public function resolveSeoTask(SeoProjectTask $projectTask): ?SeoTask
    {
        $isContentRewrite = (string) $projectTask->type === SeoProjectTask::TYPE_REWRITE
            && SeoProjectTask::normalizeRewriteMode($projectTask->rewrite_mode ?? null)
                === SeoProjectTask::REWRITE_MODE_CONTENT;

        $taskId = $isContentRewrite
            ? ($this->settings->getRewriteArticleTaskId() ?? $this->settings->getPublishArticleTaskId())
            : $this->settings->getPublishArticleTaskId();

        if ($taskId === null) {
            return null;
        }

        $task = SeoTask::query()->find($taskId);

        return $task instanceof SeoTask ? $task : null;
    }

    public function findStep(SeoProjectTask $projectTask, string $nodeId): ?array
    {
        foreach ($this->listRerunnableSteps($projectTask) as $step) {
            if ($step['node_id'] === $nodeId) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function detectKind(array $node, ?SeoPrompt $prompt): string
    {
        $title = mb_strtolower(trim((string) ($node['title'] ?? '')));
        $tools = $prompt !== null
            ? ImageToolType::fromMixed($prompt->tools ?? 'default')
            : ImageToolType::Default;

        if ($tools->isImagePipeline()) {
            return 'image';
        }

        if ($tools === ImageToolType::Video) {
            return 'video';
        }

        $haystack = $title;
        if ($prompt !== null) {
            $haystack .= ' '.mb_strtolower(trim((string) ($prompt->name ?? '')));
        }

        return match (true) {
            str_contains($haystack, 'outline') || str_contains($haystack, 'dàn ý') || str_contains($haystack, 'dan y') => 'outline',
            str_contains($haystack, 'faq') || str_contains($haystack, 'hỏi đáp') || str_contains($haystack, 'hoi dap') => 'faq',
            str_contains($haystack, 'meta title') || str_contains($haystack, 'seo title') => 'meta_title',
            str_contains($haystack, 'meta description') || str_contains($haystack, 'meta desc') => 'meta_description',
            str_contains($haystack, 'slug') => 'slug',
            str_contains($haystack, 'nội dung') || str_contains($haystack, 'noi dung')
                || str_contains($haystack, 'content') || str_contains($haystack, 'viết bài')
                || str_contains($haystack, 'viet bai') || str_contains($haystack, 'article') => 'content',
            default => 'prompt',
        };
    }

    private function labelForKind(string $kind, string $title): string
    {
        return match ($kind) {
            'outline' => 'Tạo lại outline',
            'content' => 'Viết lại nội dung',
            'image' => 'Tạo lại ảnh',
            'meta_title' => 'Tạo lại meta title',
            'meta_description' => 'Tạo lại meta description',
            'slug' => 'Tạo lại slug',
            'faq' => 'Tạo lại FAQ',
            'video' => 'Tạo lại video',
            default => 'Chạy lại: '.$title,
        };
    }
}
