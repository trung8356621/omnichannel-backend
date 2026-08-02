<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

/**
 * Publishing Queue "Unscheduled" bucket — handed off, no schedule time yet,
 * not published/failed/publishing/scheduled. Default catch-all bucket.
 */
final class PublishingQueueUnscheduledDefinition
{
    public const FILTER = 'unscheduled';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        return ! PublishingQueuePublishedDefinition::matches($row)
            && ! PublishingQueueFailedDefinition::matches($row)
            && ! PublishingQueuePublishingDefinition::matches($row)
            && ! PublishingQueueScheduledDefinition::matches($row);
    }
}
