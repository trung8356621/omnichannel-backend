<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMainRole
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if (!$user || !in_array((string) $user->role, ['owner', 'admin'], true)) {
            if ($request->is('seo/domains')) {
                abort(403, 'Bạn không có quyền truy cập');
            }

            return redirect('/seo/domains')->with('error', 'Bạn không có quyền truy cập');
        }

        return $next($request);
    }
}
