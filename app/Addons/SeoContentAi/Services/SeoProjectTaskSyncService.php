<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
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
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string, loai_san_pham?: string|null, gallery_description?: string|null, description?: string|null, post_type?: string|null}>  $tasksData
     */
    public function assertWithinMonthlyLimit(Carbon|string $month, array $tasksData): void
    {
        $carbonMonth = $this->normalizeMonth($month);

        if (now()->gt($carbonMonth->copy()->endOfMonth()->endOfDay())) {
            throw ValidationException::withMessages([
                'tasks_data' => __('seo-content-ai::filament.projects.execution_month_closed', [
                    'month' => $carbonMonth->format('m/Y'),
                ]),
            ]);
        }

        $count = $this->countEffectiveTasks($tasksData);
        $max = $carbonMonth->daysInMonth;

        if ($count > $max) {
            throw ValidationException::withMessages([
                'tasks_data' => "Tháng {$carbonMonth->format('m/Y')} chỉ có tối đa {$max} ngày. "
                    ."Bạn không thể đăng ký {$count} bài viết/từ khóa.",
            ]);
        }
    }

    /**
     * @param  list<mixed>  $tasksData
     */
    public function countEffectiveTasks(array $tasksData): int
    {
        return count(array_filter($tasksData, static fn (mixed $row): bool => is_array($row)
            && trim((string) ($row['source_content'] ?? '')) !== ''));
    }

    /**
     * Đồng bộ danh sách task: xóa cũ, tạo mới, gán target_date tuần tự (ngày 1..n trong tháng).
     *
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string, loai_san_pham?: string|null, gallery_description?: string|null, description?: string|null, post_type?: string|null}>  $tasksData
     */
    public function sync(SeoProject $project, array $tasksData): void
    {
        $sanitized = $this->sanitizeTasksData($tasksData, $project->site_id !== null ? (int) $project->site_id : null);
        $carbonMonth = $project->monthCarbon();

        $this->assertWithinMonthlyLimit($carbonMonth, $sanitized);

        $existing = $project->tasks()
            ->get()
            ->keyBy(static fn (SeoProjectTask $task): string => self::taskMatchKey(
                (int) $task->site_id,
                (string) $task->type,
                (string) $task->source_content,
            ));

        $newTaskCount = collect($sanitized)
            ->filter(function (array $task) use ($existing): bool {
                $key = self::taskMatchKey(
                    (int) $task['site_id'],
                    (string) $task['type'],
                    (string) $task['source_content'],
                );

                return ! $existing->has($key);
            })
            ->count();

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $sanitized, $carbonMonth, $existing): void {
            $project->tasks()->delete();

            $usedArticleIds = [];

            foreach ($sanitized as $index => $task) {
                $key = self::taskMatchKey(
                    (int) $task['site_id'],
                    (string) $task['type'],
                    (string) $task['source_content'],
                );
                $previous = $existing->get($key);
                $articleId = self::resolveArticleIdForRecreate($previous?->article_id, $usedArticleIds);

                if ($articleId === null && in_array((string) $task['type'], SeoProjectTask::articlePickerTypes(), true)) {
                    $articleId = self::resolveArticleIdByTitle(
                        (string) $task['source_content'],
                        (int) $task['site_id'],
                        $usedArticleIds,
                    );
                }

                $project->tasks()->create([
                    'site_id' => $task['site_id'],
                    'article_id' => $articleId,
                    'type' => $task['type'],
                    'post_type' => $task['post_type'] ?? null,
                    'loai_san_pham' => $task['loai_san_pham'] ?? null,
                    'source_content' => $task['source_content'],
                    'description' => $task['description'] ?? null,
                    'rewrite_mode' => $task['rewrite_mode'] ?? $previous?->rewrite_mode ?? SeoProjectTask::REWRITE_MODE_KEYWORD,
                    'rewrite_notes' => $task['rewrite_notes'] ?? $previous?->rewrite_notes,
                    'target_date' => $carbonMonth->copy()->addDays($index)->format('Y-m-d'),
                    'status' => $previous?->status ?? SeoProjectTask::STATUS_PENDING,
                ]);
            }

            $project->update([
                'total_tasks' => count($sanitized),
            ]);
        });

        $project = $project->fresh();
        app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($project);

        if ($newTaskCount > 0) {
            app(SeoNotificationService::class)->notifyProjectOwnerTasksAdded($project, $newTaskCount);
        }
    }

    /**
     * @param  list<array{type?: string, site_id?: int|string|null, source_content?: string|null, loai_san_pham?: string|null, gallery_description?: string|null, description?: string|null, post_type?: string|null}>  $tasksData
     * @return list<array{type: string, site_id: int, source_content: string, loai_san_pham: ?string, description: ?string, post_type: ?string}>
     */
    public function sanitizeTasksData(array $tasksData, ?int $defaultSiteId = null): array
    {
        $out = [];
        $seen = [];
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
            if (! in_array($type, SeoProjectTask::typeKeys(), true)) {
                $type = SeoProjectTask::TYPE_NEW_KEYWORD;
            }

            $item = [
                'site_id' => $siteId,
                'type' => $type,
                'source_content' => $content,
                'loai_san_pham' => null,
                'description' => null,
                'post_type' => null,
            ];

            if (SeoProjectTask::isNewArticleType($type)) {
                $postType = SeoProjectTask::normalizePostType($row['post_type'] ?? null);
                $item['post_type'] = $postType;

                if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
                    $loaiSanPham = trim((string) ($row['loai_san_pham'] ?? ''));
                    $item['loai_san_pham'] = $loaiSanPham !== '' ? $loaiSanPham : null;

                    $galleryDescription = trim((string) ($row['gallery_description'] ?? $row['description'] ?? ''));
                    $item['description'] = $galleryDescription !== '' ? $galleryDescription : null;
                }
            }

            if ($type === SeoProjectTask::TYPE_REWRITE) {
                $rewriteMode = SeoProjectTask::normalizeRewriteMode($row['rewrite_mode'] ?? null);
                $item['rewrite_mode'] = $rewriteMode;

                $rewriteNotes = trim((string) ($row['rewrite_notes'] ?? ''));
                $item['rewrite_notes'] = $rewriteMode === SeoProjectTask::REWRITE_MODE_CONTENT && $rewriteNotes !== ''
                    ? $rewriteNotes
                    : null;
            }

            $dedupeKey = self::taskMatchKey($siteId, $type, $content);
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tasksData
     */
    public function tasksSignature(array $tasksData, ?int $defaultSiteId = null): string
    {
        return json_encode(
            $this->sanitizeTasksData($tasksData, $defaultSiteId),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function taskMatchKey(int $siteId, string $type, string $sourceContent): string
    {
        return implode('|', [
            $siteId,
            $type,
            mb_strtolower(trim($sourceContent)),
        ]);
    }

    /**
     * @param  array<int, true>  $usedArticleIds
     */
    private static function resolveArticleIdForRecreate(?int $articleId, array &$usedArticleIds): ?int
    {
        $normalized = (int) ($articleId ?? 0);
        if ($normalized <= 0) {
            return null;
        }

        if (isset($usedArticleIds[$normalized])) {
            return null;
        }

        SeoProjectTask::query()
            ->where('article_id', $normalized)
            ->update(['article_id' => null]);

        $usedArticleIds[$normalized] = true;

        return $normalized;
    }

    /**
     * @param  array<int, true>  $usedArticleIds
     */
    private static function resolveArticleIdByTitle(string $title, int $siteId, array &$usedArticleIds): ?int
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $query = SeoArticle::query()
            ->where('title', $title);

        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $articleId = (int) ($query->orderByDesc('id')->value('id') ?? 0);
        if ($articleId <= 0 || isset($usedArticleIds[$articleId])) {
            return null;
        }

        SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->update(['article_id' => null]);

        $usedArticleIds[$articleId] = true;

        return $articleId;
    }

    /**
     * @return list<array{type: string, site_id: int|null, source_content: string, loai_san_pham: ?string, description: ?string, post_type: ?string}>
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
                'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                    && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                        ? $task->loai_san_pham
                        : null,
                'description' => SeoProjectTask::isNewArticleType($task->type)
                    && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                        ? $task->description
                        : null,
                'post_type' => SeoProjectTask::isNewArticleType($task->type)
                    ? SeoProjectTask::normalizePostType($task->post_type)
                    : null,
                'rewrite_mode' => $task->type === SeoProjectTask::TYPE_REWRITE
                    ? SeoProjectTask::normalizeRewriteMode($task->rewrite_mode)
                    : null,
                'rewrite_notes' => $task->type === SeoProjectTask::TYPE_REWRITE
                    ? $task->rewrite_notes
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

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountOwnerId() ?? (int) auth()->id());
        }

        return $query->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
