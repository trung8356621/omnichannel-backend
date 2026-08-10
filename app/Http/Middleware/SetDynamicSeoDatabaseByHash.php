<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class SetDynamicSeoDatabaseByHash
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hashId = $this->resolveHashId($request);

        if ($hashId === null) {
            return redirect()->to('/seo', $request->isMethodSafe() ? 301 : 302);
        }

        if (! SeoConnectionContext::isValidHashFormat($hashId)) {
            return redirect()->to('/seo', $request->isMethodSafe() ? 301 : 302);
        }

        try {
            $connection = $this->databaseConnection->bootstrapByHash($hashId);
        } catch (RuntimeException) {
            return redirect()->to('/seo', $request->isMethodSafe() ? 301 : 302);
        }

        SeoConnectionContext::applyUrlDefaults($hashId);

        $user = auth()->user();
        if ($user !== null && ! $this->databaseConnection->userCanAccessConnection($user, $connection)) {
            abort(403, 'Tài khoản của bạn không có quyền truy cập vào không gian lưu trữ SEO này.');
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
}
