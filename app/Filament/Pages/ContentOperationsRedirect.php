<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectOperationsCenter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;

/**
 * Admin alias for Content Project Operation Center.
 * Real data lives on SEO panel (/seo/content-operations) with omi_seo_ai.
 */
final class ContentOperationsRedirect extends Page
{
    protected static ?string $slug = 'content-operations';

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?int $navigationSort = 40;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.content-operations-redirect';

    public static function getNavigationLabel(): string
    {
        return 'Content Operations';
    }

    public function getTitle(): string
    {
        return 'Content Operations';
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessContentOperations();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);

        try {
            if (class_exists(ContentProjectOperationsCenter::class)) {
                $this->redirect(ContentProjectOperationsCenter::getUrl(panel: 'seo'));
            }
        } catch (\Throwable) {
            // Stay on page with manual link when connection_hash unavailable.
        }
    }

    public function getSeoOperationsUrl(): string
    {
        if (class_exists(ContentProjectOperationsCenter::class)) {
            try {
                return ContentProjectOperationsCenter::getUrl(panel: 'seo');
            } catch (\Throwable) {
                // connection_hash may be missing outside SEO panel context
            }
        }

        return url('/seo');
    }
}
