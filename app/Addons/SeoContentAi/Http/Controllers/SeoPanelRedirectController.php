<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SeoPanelRedirectController extends Controller
{
    public function __invoke(Request $request, SeoDatabaseConnectionService $databaseConnection): RedirectResponse|View
    {
        if (! SeoAccessControl::canAccessSeoPanel()) {
            $hash = $databaseConnection->resolveRedirectHash();

            if ($hash === null) {
                return view('seo-content-ai::pages.no-seo-workspace');
            }

            return redirect('/seo/'.$hash.'/login');
        }

        $hash = $databaseConnection->resolveRedirectHash(auth()->user());

        if ($hash === null) {
            return view('seo-content-ai::pages.no-seo-workspace');
        }

        return redirect('/seo/'.$hash);
    }
}
