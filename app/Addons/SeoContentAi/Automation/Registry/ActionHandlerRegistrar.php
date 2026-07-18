<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Registry;

use App\Addons\SeoContentAi\Automation\Actions\Article\CreateArticleAction;
use App\Addons\SeoContentAi\Automation\Actions\Article\UpdateArticleContentAction;
use App\Addons\SeoContentAi\Automation\Actions\Article\UpdateArticleSeoMetaAction;
use App\Addons\SeoContentAi\Automation\Actions\Foundation\PingAction;
use App\Addons\SeoContentAi\Automation\Actions\Keyword\AssignKeywordToProjectAction;
use App\Addons\SeoContentAi\Automation\Actions\Keyword\SaveKeywordVocabularyAction;
use App\Addons\SeoContentAi\Automation\Actions\Keyword\SyncKeywordTopicClusterAction;
use App\Addons\SeoContentAi\Automation\Actions\Project\AttachArticleToProjectTaskAction;
use App\Addons\SeoContentAi\Automation\Actions\Project\CreateProjectTaskAction;
use App\Addons\SeoContentAi\Automation\Actions\Project\MarkProjectTaskCompletedAction;
use App\Addons\SeoContentAi\Automation\Actions\Seo\CreateProjectTaskFromSeoIssueAction;
use App\Addons\SeoContentAi\Automation\Actions\Seo\RunSeoAuditAction;
use App\Addons\SeoContentAi\Automation\Contracts\BusinessAction;

final class ActionHandlerRegistrar
{
    /**
     * @return list<class-string<BusinessAction>>
     */
    public static function handlers(): array
    {
        return [
            PingAction::class,
            CreateArticleAction::class,
            UpdateArticleContentAction::class,
            UpdateArticleSeoMetaAction::class,
            CreateProjectTaskAction::class,
            AttachArticleToProjectTaskAction::class,
            MarkProjectTaskCompletedAction::class,
            RunSeoAuditAction::class,
            CreateProjectTaskFromSeoIssueAction::class,
            AssignKeywordToProjectAction::class,
            SaveKeywordVocabularyAction::class,
            SyncKeywordTopicClusterAction::class,
        ];
    }

    public function register(ActionRegistry $registry): void
    {
        foreach (self::handlers() as $handlerClass) {
            $registry->registerHandler($handlerClass);
        }
    }
}
