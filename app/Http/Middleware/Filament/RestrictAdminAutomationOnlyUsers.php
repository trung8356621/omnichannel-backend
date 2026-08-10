<?php

declare(strict_types=1);

namespace App\Http\Middleware\Filament;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Managers with Automation permission may enter /admin only for Automation routes.
 * Core admin/owner keep full panel access.
 */
final class RestrictAdminAutomationOnlyUsers
{
    /** @var list<string> */
    private const ALLOWED_PREFIXES = [
        'admin/automation',
        'admin/automation-rules',
        'admin/automation-executions',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if (in_array((string) $user->role, [User::ROLE_ADMIN, User::ROLE_OWNER], true)) {
            return $next($request);
        }

        if (! SeoAccessControl::canViewAutomation()) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        // Filament/Livewire internals
        if ($path === 'livewire/update'
            || str_starts_with($path, 'livewire/')
            || str_starts_with($path, 'filament/')
        ) {
            return $next($request);
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $next($request);
            }
        }

        return redirect('/admin/automation/flows');
    }
}
