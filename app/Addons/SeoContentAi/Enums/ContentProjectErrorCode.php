<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum ContentProjectErrorCode: string
{
    case TaskNotFound = 'CONTENT_PROJECT_TASK_NOT_FOUND';
    case TaskArchived = 'CONTENT_PROJECT_TASK_ARCHIVED';
    case TaskCancelled = 'CONTENT_PROJECT_TASK_CANCELLED';
    case OperationAlreadyProcessing = 'CONTENT_PROJECT_OPERATION_ALREADY_PROCESSING';
    case OperationAlreadyProcessed = 'CONTENT_PROJECT_OPERATION_ALREADY_PROCESSED';
    case ArticleRelationMissing = 'CONTENT_PROJECT_ARTICLE_RELATION_MISSING';
    case ArticleRelationConflict = 'CONTENT_PROJECT_ARTICLE_RELATION_CONFLICT';
    case ArticleAlreadyLinked = 'CONTENT_PROJECT_ARTICLE_ALREADY_LINKED';
    case RunItemNotFound = 'CONTENT_PROJECT_RUN_ITEM_NOT_FOUND';
    case ExternalWorkflowFailed = 'CONTENT_PROJECT_EXTERNAL_WORKFLOW_FAILED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }
}
