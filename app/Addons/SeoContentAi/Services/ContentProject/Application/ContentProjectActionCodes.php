<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application;

/**
 * Mã kết quả chuẩn — Filament/API/Agent đọc code, không parse message.
 */
final class ContentProjectActionCodes
{
    public const PROJECT_CREATED = 'project.created';

    public const PROJECT_UPDATED = 'project.updated';

    public const ITEMS_ADDED = 'items.added';

    public const ITEMS_UPDATED = 'items.updated';

    public const ITEMS_GENERATE_REQUESTED = 'items.generate_requested';

    public const ITEMS_REVIEW_STARTED = 'items.review_started';

    public const ITEMS_APPROVED = 'items.approved';

    public const ITEMS_SCHEDULED = 'items.scheduled';

    public const ITEMS_UNSCHEDULED = 'items.unscheduled';

    public const ITEMS_PUBLISH_QUEUED = 'items.publish_queued';

    public const ITEMS_PUBLISH_RETRIED = 'items.publish_retried';

    public const ITEMS_PUBLISH_SKIPPED = 'items.publish_skipped';

    public const ITEMS_PUBLISH_CANCELLED = 'items.publish_cancelled';

    public const PROJECT_ARCHIVED = 'project.archived';

    public const ITEMS_ARCHIVED = 'items.archived';

    public const PROJECT_RESTORED = 'project.restored';

    public const PREVIEW_READY = 'preview.ready';

    public const IDEMPOTENT_REPLAY = 'idempotent.replay';

    public const PROCESSING = 'processing';

    public const LIFECYCLE_INVALID = 'lifecycle.invalid_transition';

    public const PROJECT_ARCHIVED_BLOCK = 'project.archived';

    public const ITEMS_NOT_FOUND = 'items.not_found';

    public const PROJECT_NOT_FOUND = 'project.not_found';

    public const FORBIDDEN = 'auth.forbidden';

    public const PUBLISHING_ALREADY_PROCESSING = 'publishing.already_processing';

    public const PUBLISHING_NOT_DUE = 'publishing.not_due';

    public const WORDPRESS_UNAVAILABLE = 'wordpress.connection_unavailable';

    public const LOCK_BUSY = 'concurrency.lock_busy';

    public const OPERATION_LOCKED = 'operation.locked';

    public const OPERATION_ALREADY_PROCESSING = 'operation.already_processing';

    public const CONFIRMATION_REQUIRED = 'confirmation.required';

    public const CONFIRMATION_INVALID = 'confirmation.invalid';

    public const CONFIRMATION_EXPIRED = 'confirmation.expired';

    public const CONFIRMATION_STALE = 'confirmation.stale';

    public const ITEMS_SYNCED = 'items.synced';

    public const EXECUTION_STOPPED = 'execution.stopped';

    public const EXECUTION_RESUMED = 'execution.resumed';

    public const QUOTA_DENIED = 'quota.denied';

    public const VALIDATION_FAILED = 'validation.failed';

    public const FAILED = 'failed';
}
