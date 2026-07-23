<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Enums\ArticleReviewActionType;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleReviewService;
use App\Addons\SeoContentAi\Services\Exceptions\ArticleReviewException;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * UI dashboard card cũ (`archive-dashboard`) + nguồn dữ liệu article-level mới
 * (`articles.review_status = archived` / {@see ArticleReviewService}).
 * Không còn Filament Table thô; không đọc `seo_project_archives*`.
 */
final class ContentProjectArchive extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.content-project-archive';

    protected static bool $shouldRegisterNavigation = false;

    /** Selected global site, or 0 when All domains. */
    public int $siteId = 0;

    /**
     * Site IDs the current user may view on this page.
     *
     * @var list<int>
     */
    public array $scopedSiteIds = [];

    public ?int $reopenSubmittingId = null;

    public function mount(): void
    {
        self::authorizeResourceAccess();

        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null && $globalSiteId > 0) {
            abort_unless(SeoAccessControl::canAccessSite($globalSiteId), 403);

            $this->siteId = $globalSiteId;
            $this->scopedSiteIds = [$globalSiteId];

            return;
        }

        // All domains: scope to accessible sites only (không 403).
        $this->siteId = 0;
        $this->scopedSiteIds = SeoAccessControl::accessibleSiteIds();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.projects.archive_dashboard_heading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_projects')
                ->label(__('seo-content-ai::filament.projects.back_to_projects'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('index')),
        ];
    }

    public function canReopenArchivedArticles(): bool
    {
        return SeoAccessControl::canFinalizeArticleReview() || SeoAccessControl::canApproveArticleReview();
    }

    public function reopenArticle(int $articleId): void
    {
        abort_unless($this->canReopenArchivedArticles(), 403);

        if ($articleId <= 0) {
            $this->skipRender();

            return;
        }

        $this->reopenSubmittingId = $articleId;

        try {
            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.unarchive_item_not_found'))
                    ->danger()
                    ->send();

                return;
            }

            $siteId = (int) ($article->site_id ?? 0);
            if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
                abort(403);
            }

            if ($this->scopedSiteIds !== [] && ! in_array($siteId, $this->scopedSiteIds, true)) {
                abort(403);
            }

            $user = auth()->user();
            if (! $user instanceof User) {
                abort(403);
            }

            app(ArticleReviewService::class)->performAction(
                $article,
                $user,
                ArticleReviewActionType::Reopen,
            );

            Notification::make()
                ->title(__('seo-content-ai::filament.article_review.success.reopen'))
                ->success()
                ->send();

            $this->redirect(static::getUrl());
        } catch (ArticleReviewException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'content_project_archive.reopen',
                'article_id' => $articleId,
            ]);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.unarchive_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->reopenSubmittingId = null;
        }
    }
}
