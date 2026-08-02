<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

/**
 * Publishing Queue "Scheduled" bucket — future scheduled_publish_at, not yet due,
 * not processing/published/failed.
 */
final class PublishingQueueScheduledDefinition
{
    public const FILTER = 'scheduled';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (PublishingQueuePublishedDefinition::matches($row)
            || PublishingQueueFailedDefinition::matches($row)
            || PublishingQueuePublishingDefinition::matches($row)
        ) {
            return false;
        }

        $at = PublishingQueuePublishingDefinition::scheduledAt($row);

        return $at !== null && $at->gt(now());
    }
}
