<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Models\SeoDatabaseConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cookie;

final class SeoAccessControl
{
    private const GLOBAL_SITE_COOKIE = 'seo_global_site_id';

    private const GLOBAL_CONTENT_PROJECT_COOKIE = 'seo_global_content_project_id';

    private const GLOBAL_CONTENT_PROJECT_SITE_COOKIE = 'seo_global_content_project_site_id';

    private const GLOBAL_SITE_COOKIE_MINUTES = 60 * 24 * 365;

    public const ROLE_MANAGER = 'manager';

    public const ROLE_PLANNER = 'planner';

    public const ROLE_CONTENT_MANAGER = 'content_manager';

    /** @var array<string, int> */
    private const ROLE_RANK = [
        self::ROLE_CONTENT_MANAGER => 1,
        self::ROLE_PLANNER => 2,
        self::ROLE_MANAGER => 3,
    ];

    public static function actualRole(): string
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (($user?->role ?? null) === User::ROLE_ADMIN) {
            return self::ROLE_MANAGER;
        }

        return self::normalizeRole((string) ($user?->seo_role ?? self::ROLE_CONTENT_MANAGER));
    }

    public static function effectiveRole(): string
    {
        $actual = self::actualRole();
        $simulated = self::normalizeRole((string) session('seo_simulated_role', $actual));

        return in_array($simulated, self::allowedSimulationTargets($actual), true)
            ? $simulated
            : $actual;
    }

    /**
     * @return list<string>
     */
    public static function allowedSimulationTargets(?string $actualRole = null): array
    {
        $actualRole = self::normalizeRole((string) ($actualRole ?? self::actualRole()));

        return match ($actualRole) {
            self::ROLE_MANAGER => [self::ROLE_CONTENT_MANAGER, self::ROLE_PLANNER, self::ROLE_MANAGER],
            self::ROLE_PLANNER => [self::ROLE_CONTENT_MANAGER, self::ROLE_PLANNER],
            default => [self::ROLE_CONTENT_MANAGER],
        };
    }

    public static function canAccessManagerFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_MANAGER);
    }

    public static function canArchiveContentProjects(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessManagerFeatures();
    }

    public static function canViewProjectArchives(): bool
    {
        return self::canAccessManagerFeatures();
    }

    public static function canViewArticleArchive(): bool
    {
        return self::canViewProjectArchives();
    }

    public static function canReviewKeywords(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessPlannerFeatures();
    }

    public static function canRestoreKeywords(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessManagerFeatures();
    }

    public static function canManageKeywordReviewReasons(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessManagerFeatures();
    }

    public static function canOverrideKeywordReviewSeverity(): bool
    {
        return self::canManageKeywordReviewReasons();
    }

    public static function canAccessPlannerFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_PLANNER);
    }

    public static function canAccessContentFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_CONTENT_MANAGER);
    }

    public static function canAccessSeoPanel(?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        if (in_array((string) $user->role, [User::ROLE_ADMIN, User::ROLE_OWNER], true)) {
            return true;
        }

        return $user->isStaff()
            && (int) $user->parent_id > 0
            && filled($user->seo_role);
    }

    public static function canManageWordPressPlugin(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_ADMIN, User::ROLE_OWNER], true);
    }

    public static function isContentManager(): bool
    {
        return self::effectiveRole() === self::ROLE_CONTENT_MANAGER;
    }

    public static function isPlanner(): bool
    {
        return self::effectiveRole() === self::ROLE_PLANNER;
    }

    public static function shouldShowGlobalSeoBar(): bool
    {
        return ! self::isContentManager();
    }

    public static function shouldShowGlobalSitePicker(): bool
    {
        if (request()->routeIs('filament.seo.resources.keywords.*')) {
            return false;
        }

        if (request()->routeIs('filament.seo.pages.performance-hub')) {
            $source = (string) request()->query('source', 'gsc');

            if ($source !== '' && $source !== 'gsc') {
                return false;
            }
        }

        return true;
    }

    public static function isSeoPanelAdminViewer(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User || (string) $user->role !== User::ROLE_ADMIN) {
            return false;
        }

        return SeoConnectionContext::current() instanceof SeoDatabaseConnection
            || SeoConnectionContext::hash() !== null;
    }

    public static function isSeoPanelReadOnly(): bool
    {
        return self::isSeoPanelAdminViewer();
    }

    public static function canMutateInSeoPanel(): bool
    {
        return ! self::isSeoPanelReadOnly();
    }

    public static function shouldScopeToAccountOwner(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return true;
        }

        if ((string) $user->role !== User::ROLE_ADMIN) {
            return true;
        }

        return self::isSeoPanelAdminViewer();
    }

    public static function panelOwnerId(): ?int
    {
        $connection = SeoConnectionContext::current();
        if (! $connection instanceof SeoDatabaseConnection) {
            return null;
        }

        $owner = $connection->users()
            ->where('role', User::ROLE_OWNER)
            ->orderBy('users.id')
            ->first();

        if ($owner instanceof User) {
            return (int) $owner->id;
        }

        $fallback = $connection->users()->orderBy('users.id')->first();

        return $fallback instanceof User ? (int) $fallback->id : null;
    }

    public static function guardSeoPanelMutation(): void
    {
        abort_if(self::isSeoPanelReadOnly(), 403);
    }

    public static function canMutateContentProjects(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessPlannerFeatures();
    }

    public static function canAccessContentProjectRun(?SeoProject $project): bool
    {
        if (! $project instanceof SeoProject) {
            return false;
        }

        if (self::canAccessPlannerFeatures()) {
            return true;
        }

        return self::isContentManager()
            && (int) $project->user_id === (int) auth()->id();
    }

    public static function canRetryProjectRunItem(?SeoProject $project = null): bool
    {
        if (self::canMutateInSeoPanel() && self::canAccessPlannerFeatures()) {
            return true;
        }

        if (! self::isContentManager()) {
            return false;
        }

        if ($project === null) {
            return true;
        }

        return (int) $project->user_id === (int) auth()->id();
    }

    public static function canDeleteSeoMedia(): bool
    {
        return self::canMutateInSeoPanel() && ! self::isContentManager();
    }

    public static function canSyncArticlesToWordPress(): bool
    {
        return self::canMutateInSeoPanel() && ! self::isContentManager();
    }

    /**
     * @return list<int>
     */
    public static function accessibleSiteIds(): array
    {
        return self::accessibleSitesQuery()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Lọc theo site_id bằng danh sách đã resolve trên DB core — tránh subquery cross-database trên hosting.
     */
    public static function applyAccessibleSiteScope(Builder $query, string $column = 'site_id'): Builder
    {
        if (! self::shouldScopeToAccountOwner()) {
            return $query;
        }

        $siteIds = self::accessibleSiteIds();
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $siteIds);
    }

    public static function accountSiteOwnerId(): int
    {
        return self::accountOwnerId() ?? (int) auth()->id();
    }

    /**
     * @return Builder<Site>
     */
    public static function accessibleSitesQuery(): Builder
    {
        $query = Site::query();

        if (! self::shouldScopeToAccountOwner()) {
            return $query;
        }

        return $query->where('user_id', self::accountSiteOwnerId());
    }

    public static function canAccessSite(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        return self::accessibleSitesQuery()->whereKey($siteId)->exists();
    }

    public static function canAccessArticle(SeoArticle $article): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if (! self::canAccessSeoPanel($user)) {
            return false;
        }

        if ((string) $user->role === User::ROLE_OWNER) {
            return true;
        }

        if ((string) $user->role === User::ROLE_ADMIN && ! self::isSeoPanelAdminViewer()) {
            return true;
        }

        if (self::isContentManager()) {
            return ArticleResource::canContentManagerAccessArticle($article);
        }

        $siteId = (int) ($article->site_id ?? 0);

        return $siteId > 0 && self::canAccessSite($siteId);
    }

    public static function shouldApplyGlobalSiteScope(): bool
    {
        return ! self::isContentManager() && self::globalSiteId() !== null;
    }

    public static function accountOwnerId(): ?int
    {
        if (self::isSeoPanelAdminViewer()) {
            return self::panelOwnerId();
        }

        /** @var User|null $user */
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        return $user->isStaff() && (int) $user->parent_id > 0
            ? (int) $user->parent_id
            : (int) $user->id;
    }

    public static function globalSiteId(): ?int
    {
        if (self::isContentManager()) {
            return null;
        }

        $cookieSiteId = request()->cookie(self::GLOBAL_SITE_COOKIE);
        $siteId = $cookieSiteId !== null && $cookieSiteId !== ''
            ? $cookieSiteId
            : session('seo_global_site_id');

        if ($siteId === null || $siteId === '') {
            return null;
        }

        $siteId = (int) $siteId;

        if ((int) session('seo_global_site_id', -1) !== $siteId) {
            session(['seo_global_site_id' => $siteId]);
        }

        if ($siteId <= 0) {
            return null;
        }

        return $siteId;
    }

    public static function hasGlobalSiteSelection(): bool
    {
        return request()->cookie(self::GLOBAL_SITE_COOKIE) !== null
            || session()->has('seo_global_site_id');
    }

    public static function hasGlobalSiteScope(): bool
    {
        return self::globalSiteId() !== null;
    }

    public static function setGlobalSiteId(?int $siteId): void
    {
        $previousSiteId = self::globalSiteId();
        $storedSiteId = $siteId !== null && $siteId > 0 ? $siteId : 0;
        $nextSiteId = $storedSiteId > 0 ? $storedSiteId : null;

        if ($previousSiteId !== $nextSiteId) {
            self::clearGlobalContentProjectSelection();
        }

        session(['seo_global_site_id' => $storedSiteId]);
        Cookie::queue(cookie(
            self::GLOBAL_SITE_COOKIE,
            (string) $storedSiteId,
            self::GLOBAL_SITE_COOKIE_MINUTES,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax',
        ));
    }

    public static function clearGlobalSiteSelection(): void
    {
        session()->forget('seo_global_site_id');
        Cookie::queue(Cookie::forget(self::GLOBAL_SITE_COOKIE, '/'));
        self::clearGlobalContentProjectSelection();
    }

    public static function canUseGlobalContentProjectPicker(): bool
    {
        return self::isPlanner() && self::globalSiteId() !== null;
    }

    public static function globalContentProjectId(): ?int
    {
        if (! self::canUseGlobalContentProjectPicker()) {
            return null;
        }

        $siteId = (int) self::globalSiteId();
        $storedSiteId = self::resolveStoredGlobalContentProjectSiteId();
        $projectId = self::resolveStoredGlobalContentProjectId();

        if ($storedSiteId !== $siteId || $projectId === null) {
            return null;
        }

        if (! self::isAssignableGlobalContentProject($projectId, $siteId)) {
            self::clearGlobalContentProjectSelection();

            return null;
        }

        return $projectId;
    }

    public static function setGlobalContentProjectId(?int $projectId): void
    {
        $siteId = self::globalSiteId();
        if ($siteId === null || $projectId === null || $projectId <= 0) {
            self::clearGlobalContentProjectSelection();

            return;
        }

        if (! self::isAssignableGlobalContentProject($projectId, $siteId)) {
            self::clearGlobalContentProjectSelection();

            return;
        }

        session([
            'seo_global_content_project_id' => $projectId,
            'seo_global_content_project_site_id' => $siteId,
        ]);

        Cookie::queue(cookie(
            self::GLOBAL_CONTENT_PROJECT_COOKIE,
            (string) $projectId,
            self::GLOBAL_SITE_COOKIE_MINUTES,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax',
        ));
        Cookie::queue(cookie(
            self::GLOBAL_CONTENT_PROJECT_SITE_COOKIE,
            (string) $siteId,
            self::GLOBAL_SITE_COOKIE_MINUTES,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax',
        ));
    }

    public static function clearGlobalContentProjectSelection(): void
    {
        session()->forget([
            'seo_global_content_project_id',
            'seo_global_content_project_site_id',
        ]);
        Cookie::queue(Cookie::forget(self::GLOBAL_CONTENT_PROJECT_COOKIE, '/'));
        Cookie::queue(Cookie::forget(self::GLOBAL_CONTENT_PROJECT_SITE_COOKIE, '/'));
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));

        return array_key_exists($role, self::ROLE_RANK)
            ? $role
            : self::ROLE_CONTENT_MANAGER;
    }

    private static function rank(string $role): int
    {
        $role = self::normalizeRole($role);

        return self::ROLE_RANK[$role] ?? self::ROLE_RANK[self::ROLE_CONTENT_MANAGER];
    }

    private static function resolveStoredGlobalContentProjectSiteId(): ?int
    {
        $cookieSiteId = request()->cookie(self::GLOBAL_CONTENT_PROJECT_SITE_COOKIE);
        $siteId = $cookieSiteId !== null && $cookieSiteId !== ''
            ? $cookieSiteId
            : session('seo_global_content_project_site_id');

        if ($siteId === null || $siteId === '') {
            return null;
        }

        $siteId = (int) $siteId;

        return $siteId > 0 ? $siteId : null;
    }

    private static function resolveStoredGlobalContentProjectId(): ?int
    {
        $cookieProjectId = request()->cookie(self::GLOBAL_CONTENT_PROJECT_COOKIE);
        $projectId = $cookieProjectId !== null && $cookieProjectId !== ''
            ? $cookieProjectId
            : session('seo_global_content_project_id');

        if ($projectId === null || $projectId === '') {
            return null;
        }

        $projectId = (int) $projectId;

        return $projectId > 0 ? $projectId : null;
    }

    private static function isAssignableGlobalContentProject(int $projectId, int $siteId): bool
    {
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return false;
        }

        if ((int) $project->site_id !== $siteId) {
            return false;
        }

        if (! $project->isExecutionMonthOpen()) {
            return false;
        }

        return $project->canRegisterMoreTasks();
    }
}
