<?php

declare(strict_types=1);

namespace App\Http\Middleware\Filament;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RedirectStaffFromAdminPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && $user->isStaff() && ! SeoAccessControl::canAccessAdminAutomationPanel($user)) {
            return redirect('/');
        }

        return $next($request);
    }
}
