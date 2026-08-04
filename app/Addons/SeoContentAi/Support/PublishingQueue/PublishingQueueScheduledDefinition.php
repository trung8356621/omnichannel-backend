<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

use Carbon\Carbon;

/**
 * Publishing Queue "Scheduled" bucket — có scheduled_publish_at, chưa claim execution.
 *
 * Gồm cả due (quá hạn nhưng runner chưa claim) và future. Không gồm processing.
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

        $at = self::scheduledAt($row);
        if ($at === null) {
            return false;
        }

        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));

        // Waiting / retrying / none / empty / null = plan or queued, chưa execution claim.
        return in_array($queue, ['waiting', 'retrying', 'none', ''], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function scheduledAt(array $row): ?Carbon
    {
        $raw = $row['scheduled_raw'] ?? $row['scheduled_publish_at'] ?? null;
        if ($raw instanceof Carbon) {
            return $raw;
        }
        if (is_string($raw) && trim($raw) !== '') {
            try {
                return Carbon::parse($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
