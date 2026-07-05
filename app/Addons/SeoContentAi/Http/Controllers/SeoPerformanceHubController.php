<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Filament\Pages\SeoPerformanceHub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * HTTP entry for Performance Hub — delegates to Filament Livewire page inside SEO panel.
 */
final class SeoPerformanceHubController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->to(SeoPerformanceHub::getUrl());
    }
}
