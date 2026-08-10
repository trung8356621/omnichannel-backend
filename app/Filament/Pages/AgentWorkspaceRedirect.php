<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceDeepLink;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;

/**
 * Admin alias for Agent Workspace.
 * Real UI lives on SEO panel (/seo/{connection_hash}/agent).
 */
final class AgentWorkspaceRedirect extends Page
{
    protected static ?string $slug = 'agent';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 39;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.agent-workspace-redirect';

    public static function getNavigationLabel(): string
    {
        return 'Agent Workspace';
    }

    public function getTitle(): string
    {
        return 'Agent Workspace';
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessSeoPanel();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canAccessSeoPanel(), 403);

        $url = AgentWorkspaceDeepLink::tryUrl();
        if ($url !== null) {
            $this->redirect($url);
        }
    }

    public function getSeoAgentUrl(): ?string
    {
        return AgentWorkspaceDeepLink::tryUrl();
    }

    public function getMissingSiteMessage(): string
    {
        return AgentWorkspaceDeepLink::MISSING_SITE_MESSAGE;
    }
}
