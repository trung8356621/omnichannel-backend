<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

class SeoProjectTaskSyncDataNormalizer
{
    public function __construct(
        private readonly ProjectTaskSourceKeyGenerator $sourceKeys,
    ) {}

    /**
     * @param  list<mixed>  $tasksData
     * @return list<SeoProjectTaskSyncData>
     */
    public function normalize(SeoProject $project, array $tasksData, ?int $defaultSiteId = null): array
    {
        $projectId = (int) $project->id;
        $siteDefault = $defaultSiteId ?? ($project->site_id !== null ? (int) $project->site_id : null);
        $allowedSiteIds = $this->allowedSiteIds();
        $out = [];

        foreach ($tasksData as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['source_content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $siteId = (int) ($row['site_id'] ?? 0);
            if ($siteId <= 0) {
                $siteId = (int) ($siteDefault ?? 0);
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

            $postType = null;
            $loaiSanPham = null;
            $description = null;
            $rewriteMode = null;
            $rewriteNotes = null;

            if (SeoProjectTask::isNewArticleType($type)) {
                $postType = SeoProjectTask::normalizePostType($row['post_type'] ?? null);
                if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
                    $loai = trim((string) ($row['loai_san_pham'] ?? ''));
                    $loaiSanPham = $loai !== '' ? $loai : null;
                    $gallery = trim((string) ($row['gallery_description'] ?? $row['description'] ?? ''));
                    $description = $gallery !== '' ? $gallery : null;
                }
            }

            if ($type === SeoProjectTask::TYPE_REWRITE) {
                $rewriteMode = SeoProjectTask::normalizeRewriteMode($row['rewrite_mode'] ?? null);
                $notes = trim((string) ($row['rewrite_notes'] ?? ''));
                $rewriteNotes = $rewriteMode === SeoProjectTask::REWRITE_MODE_CONTENT && $notes !== ''
                    ? $notes
                    : null;
            }

            $taskIdRaw = (int) ($row['id'] ?? $row['task_id'] ?? 0);
            $taskId = $taskIdRaw > 0 ? $taskIdRaw : null;

            $sourceKey = $this->sourceKeys->generate($projectId, $type, $postType, $content);

            $targetDate = isset($row['target_date']) && trim((string) $row['target_date']) !== ''
                ? trim((string) $row['target_date'])
                : null;

            $requestedStatus = isset($row['status']) && trim((string) $row['status']) !== ''
                ? trim((string) $row['status'])
                : null;

            $out[] = new SeoProjectTaskSyncData(
                taskId: $taskId,
                projectId: $projectId,
                siteId: $siteId,
                type: $type,
                postType: $postType,
                sourceContent: $content,
                sourceKey: $sourceKey,
                rewriteMode: $rewriteMode,
                rewriteNotes: $rewriteNotes,
                description: $description,
                loaiSanPham: $loaiSanPham,
                targetDate: $targetDate,
                requestedStatus: $requestedStatus,
                inputIndex: (int) $index,
            );
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    protected function allowedSiteIds(): array
    {
        $query = Site::query();

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountOwnerId() ?? (int) auth()->id());
        }

        return $query->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
