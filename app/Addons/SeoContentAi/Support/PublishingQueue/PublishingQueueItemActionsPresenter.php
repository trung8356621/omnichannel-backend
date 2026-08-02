<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

/**
 * Presentation-only action visibility for the Publishing Queue item menu.
 * Mirrors ContentProjectItemActionsPresenter — flags only, no eligibility logic.
 * Command-bus/authorization gates still apply on the Livewire method itself.
 *
 * @phpstan-type ActionFlags array{
 *     schedule: bool,
 *     unschedule: bool,
 *     publish_now: bool,
 *     retry_publish: bool,
 *     return_to_content_project: bool,
 *     cancel: bool,
 *     open_article: bool,
 *     has_publishing: bool,
 *     has_lifecycle: bool,
 *     has_other: bool,
 * }
 */
final class PublishingQueueItemActionsPresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @return ActionFlags
     */
    public static function forRow(array $row): array
    {
        $state = strtolower((string) ($row['publish_state'] ?? PublishingQueueStateClassifier::UNSCHEDULED));
        $openArticle = ! empty($row['article_edit_url']);

        $schedule = $state === PublishingQueueStateClassifier::UNSCHEDULED;
        $unschedule = $state === PublishingQueueStateClassifier::SCHEDULED;
        // Publish now is available as soon as an item is in the queue, whether or not
        // it has been scheduled yet — scheduling is optional, not a publish precondition.
        $publishNow = in_array($state, [
            PublishingQueueStateClassifier::UNSCHEDULED,
            PublishingQueueStateClassifier::SCHEDULED,
        ], true);
        $retryPublish = $state === PublishingQueueStateClassifier::FAILED;
        $cancel = $state === PublishingQueueStateClassifier::FAILED;
        // Return to Content Project is offered for anything not actively being handled
        // by the publisher — unscheduled/scheduled/failed. Publishing/published are excluded.
        $returnToContentProject = in_array($state, [
            PublishingQueueStateClassifier::UNSCHEDULED,
            PublishingQueueStateClassifier::SCHEDULED,
            PublishingQueueStateClassifier::FAILED,
        ], true);

        return [
            'schedule' => $schedule,
            'unschedule' => $unschedule,
            'publish_now' => $publishNow,
            'retry_publish' => $retryPublish,
            'return_to_content_project' => $returnToContentProject,
            'cancel' => $cancel,
            'open_article' => $openArticle,
            'has_publishing' => $schedule || $unschedule || $publishNow || $retryPublish,
            'has_lifecycle' => $returnToContentProject || $cancel,
            'has_other' => $openArticle,
        ];
    }
}
