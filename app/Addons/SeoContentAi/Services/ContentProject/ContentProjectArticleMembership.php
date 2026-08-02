<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;

/**
 * Article thuộc Content Project đang hoạt động (project chưa archive).
 */
final class ContentProjectArticleMembership
{
    public function activeTaskForArticle(SeoArticle|int $article): ?SeoProjectTask
    {
        $articleId = $article instanceof SeoArticle ? (int) $article->getKey() : $article;
        if ($articleId <= 0) {
            return null;
        }

        $task = SeoProjectTask::query()
            ->active()
            ->where('article_id', $articleId)
            ->whereHas('project', static function ($query): void {
                $query->whereNull('archived_at');
            })
            ->orderByDesc('id')
            ->first();

        return $task instanceof SeoProjectTask ? $task : null;
    }

    public function belongsToActiveContentProject(SeoArticle|int $article): bool
    {
        return $this->activeTaskForArticle($article) instanceof SeoProjectTask;
    }

    /**
     * Article gắn task Content Project (kể cả project đã archive).
     * Dùng cho Manual Sync visibility / fail-closed — archive ≠ bài độc lập.
     */
    public function assignedTaskForArticle(SeoArticle|int $article): ?SeoProjectTask
    {
        $articleId = $article instanceof SeoArticle ? (int) $article->getKey() : $article;
        if ($articleId <= 0) {
            return null;
        }

        $task = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->orderByDesc('id')
            ->first();

        return $task instanceof SeoProjectTask ? $task : null;
    }

    public function belongsToContentProject(SeoArticle|int $article): bool
    {
        return $this->assignedTaskForArticle($article) instanceof SeoProjectTask;
    }

    public function activeProjectForArticle(SeoArticle|int $article): ?SeoProject
    {
        $task = $this->activeTaskForArticle($article);
        if (! $task instanceof SeoProjectTask) {
            return null;
        }

        $project = $task->project;
        if ($project instanceof SeoProject && $project->archived_at === null) {
            return $project;
        }

        return null;
    }
}
