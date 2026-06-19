<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeoProjectApprovalService
{
    public function approveLinkedProject(SeoArticle $article, User $user): SeoProject
    {
        if (! SeoAccessControl::isContentManager()) {
            throw ValidationException::withMessages([
                'project' => 'Chỉ content manager được đánh dấu project đã duyệt.',
            ]);
        }

        $project = $this->linkedProjectQuery($article, $user)->first();
        if (! $project instanceof SeoProject) {
            throw ValidationException::withMessages([
                'project' => 'Bài viết chưa được liên kết với project do bạn phụ trách.',
            ]);
        }

        if ($project->status === SeoProject::STATUS_APPROVED) {
            return $project;
        }

        DB::connection($project->getConnectionName())->transaction(function () use ($article, $project): void {
            $project->tasks()
                ->where('article_id', (int) $article->id)
                ->update(['article_id' => (int) $article->id]);

            $project->update(['status' => SeoProject::STATUS_APPROVED]);
        });

        app(SeoNotificationService::class)->notifyPlannersProjectApproved($project, $article);

        return $project->fresh();
    }

    public function linkedProjectId(SeoArticle $article, User $user): ?int
    {
        $projectId = $this->linkedProjectQuery($article, $user)->value('id');

        return $projectId !== null ? (int) $projectId : null;
    }

    public function contentManagerHasSubmitted(SeoArticle $article, User $user): bool
    {
        $project = $this->linkedProjectQuery($article, $user)->first();

        return $project instanceof SeoProject
            && $project->status === SeoProject::STATUS_APPROVED;
    }

    private function linkedProjectQuery(SeoArticle $article, User $user): Builder
    {
        $runMeta = $article->articleMetas()
            ->where('meta_key', 'content_project_run')
            ->value('meta_value');
        $decoded = is_string($runMeta) ? json_decode($runMeta, true) : null;
        $metaProjectId = is_array($decoded) ? (int) ($decoded['project_id'] ?? 0) : 0;
        $metaTaskId = is_array($decoded) ? (int) ($decoded['task_id'] ?? 0) : 0;

        if ($metaTaskId > 0) {
            SeoProjectTask::query()
                ->where('article_id', (int) $article->id)
                ->whereKeyNot($metaTaskId)
                ->update(['article_id' => null]);

            SeoProjectTask::query()
                ->whereKey($metaTaskId)
                ->update(['article_id' => (int) $article->id]);
        }

        return SeoProject::query()
            ->where('user_id', (int) $user->id)
            ->where(function (Builder $projects) use ($article, $metaProjectId): void {
                $projects->whereHas('tasks', function (Builder $tasks) use ($article): void {
                    $tasks->where('article_id', (int) $article->id);
                });

                if ($metaProjectId > 0) {
                    $projects->orWhere('id', $metaProjectId);
                }
            });
    }
}
