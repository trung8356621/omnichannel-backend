<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

/**
 * Publishing Queue "Publishing" bucket — publisher operation đang chạy thật.
 *
 * Chỉ `publish_queue_status=processing`. Due schedule chưa claim KHÔNG phải Publishing.
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

        return $queue === 'processing';
    }
}
