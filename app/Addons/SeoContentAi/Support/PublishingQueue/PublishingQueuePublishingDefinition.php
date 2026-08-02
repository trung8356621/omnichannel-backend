<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

use Carbon\Carbon;

/**
 * Publishing Queue "Publishing" bucket — actively being handled by the runner.
 *
 * status=processing OR (scheduled_publish_at due AND status in waiting|retrying|none).
 * Legacy rows with waiting/retrying but no scheduled_publish_at are treated as
 * already due (queue-driven, not schedule-driven).
 */
final class PublishingQueuePublishingDefinition
{
    public const FILTER = 'publishing';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (PublishingQueuePublishedDefinition::matches($row) || PublishingQueueFailedDefinition::matches($row)) {
            return false;
        }

        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));
        if ($queue === 'processing') {
            return true;
        }

        $at = self::scheduledAt($row);
        if ($at !== null) {
            return $at->lte(now()) && in_array($queue, ['waiting', 'retrying', 'none', ''], true);
        }

        // Legacy waiting/retrying without a stamped schedule — treat as due/queued.
        return in_array($queue, ['waiting', 'retrying'], true);
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
