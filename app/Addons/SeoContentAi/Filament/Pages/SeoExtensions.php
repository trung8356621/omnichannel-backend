<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Extension\ExtensionHealthService;
use App\Addons\SeoContentAi\Extension\ExtensionStateStore;
use App\Addons\SeoContentAi\Extension\Registry\ExtensionRegistry;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

final class SeoExtensions extends Page
{
    protected static ?string $slug = 'extensions';

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 85;

    protected static string $view = 'seo-content-ai::filament.pages.seo-extensions';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public bool $actionLoading = false;

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures()
            || SeoAccessControl::canAccessContentOperations();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.extensions.nav');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.extensions.title');
    }

    public function mount(
        ExtensionRegistry $extensionRegistry,
        ExtensionStateStore $stateStore,
    ): void {
        $this->loadRows($extensionRegistry, $stateStore);
    }

    public function enableExtension(string $extensionId, ExtensionRegistry $extensionRegistry): void
    {
        $this->actionLoading = true;

        try {
            $extensionRegistry->enable($extensionId);
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.enabled'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.action_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->redirect(static::getUrl(), navigate: false);
    }

    public function disableExtension(string $extensionId, ExtensionRegistry $extensionRegistry): void
    {
        $this->actionLoading = true;

        try {
            $extensionRegistry->disable($extensionId);
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.disabled'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.action_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->redirect(static::getUrl(), navigate: false);
    }

    public function refreshHealth(
        ExtensionHealthService $healthService,
        ExtensionRegistry $extensionRegistry,
        ExtensionStateStore $stateStore,
    ): void {
        $this->actionLoading = true;

        try {
            $healthService->runAll();
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.health_refreshed'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.health_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->loadRows($extensionRegistry, $stateStore);
        $this->actionLoading = false;
    }

    private function loadRows(ExtensionRegistry $extensionRegistry, ExtensionStateStore $stateStore): void
    {
        $this->rows = [];

        foreach ($extensionRegistry->installed() as $definition) {
            $id = $definition->manifest->id;
            $enabled = $stateStore->isEnabled($id);
            $status = $stateStore->getStatus($id);

            if (! $enabled) {
                $status = 'disabled';
            }

            $this->rows[] = [
                'id' => $id,
                'name' => $definition->manifest->name,
                'version' => $definition->manifest->version,
                'sdk' => $definition->manifest->sdk,
                'status' => $status,
                'enabled' => $enabled,
                'providers' => $definition->manifest->providers,
            ];
        }
    }
}
