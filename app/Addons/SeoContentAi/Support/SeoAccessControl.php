<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cookie;

final class SeoAccessControl
{
    private const GLOBAL_SITE_COOKIE = 'seo_global_site_id';

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

    public static function canAccessPlannerFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_PLANNER);
    }

    public static function canAccessContentFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_CONTENT_MANAGER);
    }

    public static function isContentManager(): bool
    {
        return self::effectiveRole() === self::ROLE_CONTENT_MANAGER;
    }

    public static function isPlanner(): bool
    {
        return self::effectiveRole() === self::ROLE_PLANNER;
    }

    public static function accountOwnerId(): ?int
    {
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
        $storedSiteId = $siteId !== null && $siteId > 0 ? $siteId : 0;

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
}
