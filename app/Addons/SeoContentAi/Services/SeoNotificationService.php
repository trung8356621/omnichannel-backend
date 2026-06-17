<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class SeoNotificationService
{
    public function notifyProjectOwner(SeoProject $project): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $owner = User::query()->find((int) $project->user_id);
        if (! $owner instanceof User || $owner->seo_role !== User::SEO_ROLE_CONTENT_MANAGER) {
            return;
        }

        Notification::make()
            ->title('Bạn có project nội dung mới')
            ->body((string) $project->name)
            ->icon('heroicon-o-folder-plus')
            ->actions([
                Action::make('open')
                    ->label('Mở project')
                    ->url(SeoConnectionContext::panelUrl('content-projects/'.$project->getKey().'/edit'))
                    ->button(),
            ])
            ->sendToDatabase($owner);
    }

    public function notifyPlannersProjectApproved(SeoProject $project, SeoArticle $article): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        foreach ($this->plannersForProject($project) as $planner) {
            Notification::make()
                ->title('Project đã được duyệt')
                ->body(sprintf('%s · %s', (string) $project->name, (string) $article->title))
                ->icon('heroicon-o-check-badge')
                ->success()
                ->actions([
                    Action::make('open')
                        ->label('Mở bài viết')
                        ->url(SeoConnectionContext::panelUrl('articles/'.$article->getKey().'/edit'))
                        ->button(),
                ])
                ->sendToDatabase($planner);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function plannersForProject(SeoProject $project): Collection
    {
        $projectOwner = User::query()->find((int) $project->user_id);
        if (! $projectOwner instanceof User) {
            return collect();
        }

        $accountOwnerId = $projectOwner->isStaff()
            ? (int) $projectOwner->parent_id
            : (int) $projectOwner->id;

        return User::query()
            ->where('status', User::STATUS_NORMAL)
            ->where('seo_role', User::SEO_ROLE_PLANNER)
            ->where(function ($query) use ($accountOwnerId): void {
                $query->whereKey($accountOwnerId)
                    ->orWhere('parent_id', $accountOwnerId);
            })
            ->get();
    }
}
