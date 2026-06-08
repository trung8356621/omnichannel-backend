<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeoProjectTaskSyncService
{
    public function maxTasksForMonth(Carbon|string $month): int
    {
        return $this->normalizeMonth($month)->daysInMonth;
    }

    public function normalizeMonth(Carbon|string $month): Carbon
    {
        return Carbon::parse($month)->startOfMonth();
    }

    /**
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string, description?: string|null, post_type?: string|null}>  $tasksData
     */
    public function assertWithinMonthlyLimit(Carbon|string $month, array $tasksData): void
    {
        $carbonMonth = $this->normalizeMonth($month);
        $count = count($this->sanitizeTasksData($tasksData, null));
        $max = $carbonMonth->daysInMonth;

        if ($count > $max) {
            throw ValidationException::withMessages([
                'tasks_data' => "Tháng {$carbonMonth->format('m/Y')} chỉ có tối đa {$max} ngày. "
                    . "Bạn không thể đăng ký {$count} bài viết/từ khóa.",
            ]);
        }
    }

    /**
     * Đồng bộ danh sách task: xóa cũ, tạo mới, gán target_date tuần tự (ngày 1..n trong tháng).
     *
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string, description?: string|null, post_type?: string|null}>  $tasksData
     */
    public function sync(SeoProject $project, array $tasksData): void
    {
        $sanitized = $this->sanitizeTasksData($tasksData, $project->site_id !== null ? (int) $project->site_id : null);
        $carbonMonth = $project->monthCarbon();

        $this->assertWithinMonthlyLimit($carbonMonth, $sanitized);

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $sanitized, $carbonMonth): void {
            $existing = $project->tasks()
                ->get()
                ->keyBy(static fn (SeoProjectTask $task): string => implode('|', [
                    (int) $task->site_id,
                    (string) $task->type,
                    mb_strtolower(trim((string) $task->source_content)),
                ]));

            $project->tasks()->delete();

            foreach ($sanitized as $index => $task) {
                $key = implode('|', [
                    (int) $task['site_id'],
                    (string) $task['type'],
                    mb_strtolower(trim((string) $task['source_content'])),
                ]);
                $previous = $existing->get($key);

                $project->tasks()->create([
                    'site_id' => $task['site_id'],
                    'article_id' => $previous?->article_id,
                    'type' => $task['type'],
                    'post_type' => $task['post_type'] ?? null,
                    'source_content' => $task['source_content'],
                    'description' => $task['description'] ?? null,
                    'target_date' => $carbonMonth->copy()->addDays($index)->format('Y-m-d'),
                    'status' => $previous?->status ?? SeoProjectTask::STATUS_PENDING,
                ]);
            }

            $project->update([
                'total_tasks' => count($sanitized),
            ]);
        });
    }

    /**
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string|null, description?: string|null}>  $tasksData
     * @return list<array{type: string, site_id: int, source_content: string, description: ?string}>
     */
    public function sanitizeTasksData(array $tasksData, ?int $defaultSiteId = null): array
    {
        $out = [];
        $allowedSiteIds = $this->allowedSiteIds();

        foreach ($tasksData as $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['source_content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $siteId = (int) ($row['site_id'] ?? 0);
            if ($siteId <= 0) {
                $siteId = (int) ($defaultSiteId ?? 0);
            }

            if ($siteId <= 0 || ! in_array($siteId, $allowedSiteIds, true)) {
                throw ValidationException::withMessages([
                    'site_id' => __('seo-content-ai::filament.projects.domain_required'),
                    'tasks_data' => __('seo-content-ai::filament.projects.domain_required'),
                ]);
            }

            $type = (string) ($row['type'] ?? SeoProjectTask::TYPE_NEW_KEYWORD);
            if (! in_array($type, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_NEW_KEYWORD], true)) {
                $type = SeoProjectTask::TYPE_NEW_KEYWORD;
            }

            $item = [
                'site_id' => $siteId,
                'type' => $type,
                'source_content' => $content,
                'description' => null,
                'post_type' => null,
            ];

            if ($type === SeoProjectTask::TYPE_NEW_KEYWORD) {
                $description = trim((string) ($row['description'] ?? ''));
                $item['description'] = $description !== '' ? $description : null;
                $item['post_type'] = SeoProjectTask::normalizePostType($row['post_type'] ?? null);
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<array{type: string, site_id: int, source_content: string, description: ?string, post_type: ?string}>
     */
    public function tasksDataFromProject(SeoProject $project): array
    {
        return $project->tasks()
            ->orderBy('target_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SeoProjectTask $task): array => [
                'site_id' => $task->site_id !== null ? (int) $task->site_id : null,
                'type' => $task->type,
                'source_content' => $task->source_content,
                'description' => $task->description,
                'post_type' => $task->type === SeoProjectTask::TYPE_NEW_KEYWORD
                    ? SeoProjectTask::normalizePostType($task->post_type)
                    : null,
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function allowedSiteIds(): array
    {
        $query = Site::query();

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', SeoAccessControl::accountOwnerId() ?? (int) auth()->id());
        }

        return $query->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
