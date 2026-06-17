<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Middleware;

use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetDynamicSeoDatabase
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hashId = $this->resolveHashId($request);

        if ($hashId !== null && SeoConnectionContext::isValidHashFormat($hashId)) {
            try {
                $this->databaseConnection->bootstrapByHash($hashId);
                SeoConnectionContext::applyUrlDefaults($hashId);
            } catch (\RuntimeException) {
                $this->databaseConnection->bootstrapLegacySharedConnection();
            }

            return $next($request);
        }

        $siteId = $this->resolveSiteId($request);

        if ($siteId !== null && $siteId > 0) {
            $this->databaseConnection->bootstrapBySiteId($siteId);
        } else {
            $this->databaseConnection->bootstrapLegacySharedConnection();
        }

        return $next($request);
    }

    private function resolveHashId(Request $request): ?string
    {
        $routeHash = $request->route('connection_hash');
        if (is_string($routeHash) && $routeHash !== '') {
            return $routeHash;
        }

        $sessionHash = session('seo_current_connection_hash');
        if (is_string($sessionHash) && $sessionHash !== '') {
            return $sessionHash;
        }

        $headerHash = trim((string) $request->header('X-SEO-Connection', ''));

        return $headerHash !== '' ? $headerHash : null;
    }

    private function resolveSiteId(Request $request): ?int
    {
        $header = trim((string) $request->header('X-Site-ID', ''));
        if ($header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        $routeSiteId = $request->route('site_id') ?? $request->route('site');
        if (is_numeric($routeSiteId)) {
            return (int) $routeSiteId;
        }

        $inputSiteId = $request->input('site_id');
        if (is_numeric($inputSiteId)) {
            return (int) $inputSiteId;
        }

        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null && $globalSiteId > 0) {
            return $globalSiteId;
        }

        return null;
    }
}
