<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Support;

use App\Addons\SeoContentAi\Enums\ContentProjectPublishQueueStatus;
use RuntimeException;

/**
 * Publish queue lifecycle — map ContentProjectPublishQueueStatus.
 *
 * none≈unscheduled, waiting≈scheduled/queued, retrying≈retry_wait.
 */
final class ContentProjectPublishTransitionGuard
{
    private const ALLOWED = [
        'none' => [
            ContentProjectPublishQueueStatus::Waiting,
            // Runner claim due Scheduled (execution none) → Publishing.
            ContentProjectPublishQueueStatus::Processing,
        ],
        'waiting' => [
            ContentProjectPublishQueueStatus::Processing,
            ContentProjectPublishQueueStatus::Cancelled,
            ContentProjectPublishQueueStatus::Skipped,
            ContentProjectPublishQueueStatus::None, // reschedule / recover plan
        ],
        'processing' => [
            ContentProjectPublishQueueStatus::Published,
            ContentProjectPublishQueueStatus::Failed,
            // Normal Cancel KHÔNG được — dùng recoverStuckPublishing.
        ],
        'failed' => [
            ContentProjectPublishQueueStatus::Retrying,
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::Cancelled,
            ContentProjectPublishQueueStatus::Skipped,
            ContentProjectPublishQueueStatus::None,
        ],
        'retrying' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::Processing,
            ContentProjectPublishQueueStatus::Cancelled,
            ContentProjectPublishQueueStatus::None,
        ],
        'cancelled' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::None,
        ],
        'skipped' => [
            ContentProjectPublishQueueStatus::Waiting,
            ContentProjectPublishQueueStatus::None,
        ],
        'published' => [
            // Republish / update existing WordPress post after local edits.
            ContentProjectPublishQueueStatus::Waiting,
        ],
    ];

    public function assertCanTransition(
        ContentProjectPublishQueueStatus|string|null $from,
        ContentProjectPublishQueueStatus|string $to,
    ): void {
        $fromStatus = $this->normalize($from);
        $toStatus = $this->normalize($to);

        if ($fromStatus === $toStatus) {
            return;
        }

        $fromKey = $fromStatus->value;
        $allowed = self::ALLOWED[$fromKey] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'lifecycle.invalid_transition: %s → %s',
                $fromKey,
                $toStatus->value,
            ));
        }
    }

    private function normalize(ContentProjectPublishQueueStatus|string|null $status): ContentProjectPublishQueueStatus
    {
        if ($status instanceof ContentProjectPublishQueueStatus) {
            return $status;
        }

        $raw = trim((string) ($status ?? ''));

        if ($raw === '') {
            return ContentProjectPublishQueueStatus::None;
        }

        return ContentProjectPublishQueueStatus::from($raw);
    }
}
