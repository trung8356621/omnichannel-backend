<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Observers;

use App\Addons\SeoContentAi\Models\SeoProject;

/**
 * Observer chỉ invariant — không business notify/WP/AI.
 * Notification đi qua Automation Engine (notification.send).
 */
final class SeoProjectObserver
{
    public bool $afterCommit = true;

    public function created(SeoProject $project): void
    {
        // No automatic business side effects.
    }

    public function updated(SeoProject $project): void
    {
        // No automatic business side effects.
    }
}
