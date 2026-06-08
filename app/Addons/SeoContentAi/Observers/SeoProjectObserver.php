<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Observers;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\SeoNotificationService;

final class SeoProjectObserver
{
    public bool $afterCommit = true;

    public function created(SeoProject $project): void
    {
        app(SeoNotificationService::class)->notifyProjectOwner($project);
    }

    public function updated(SeoProject $project): void
    {
        if ($project->wasChanged('user_id')) {
            app(SeoNotificationService::class)->notifyProjectOwner($project);
        }
    }
}
