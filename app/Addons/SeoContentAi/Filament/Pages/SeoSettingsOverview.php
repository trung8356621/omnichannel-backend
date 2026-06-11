<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Services\AiModelRouterService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsOverview extends Page
{
    protected static ?string $slug = 'settings/overview';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Overview';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-overview';

    /** @var array{connections: list<array<string, mixed>>, total_models: int, last_synced_at: ?string} */
    public array $aiModelsOverview = [
        'connections' => [],
        'total_models' => 0,
        'last_synced_at' => null,
    ];

    public function mount(AiModelRouterService $router): void
    {
        $this->refreshAiModelsOverview($router);

        if (($this->aiModelsOverview['total_models'] ?? 0) === 0) {
            $this->syncAllAiModels($router, silent: true);
        }
    }

    public function refreshAiModelsOverview(AiModelRouterService $router): void
    {
        $this->aiModelsOverview = $router->overviewForUser();
    }

    public function syncAllAiModels(AiModelRouterService $router, bool $silent = false): void
    {
        $result = $router->syncAllConnectionsForUser();

        $this->refreshAiModelsOverview($router);

        if ($silent && $result['ok'] > 0) {
            return;
        }

        $notification = Notification::make()
            ->title($result['failed'] === 0
                ? __('seo-content-ai::filament.settings_overview.ai_sync_success')
                : __('seo-content-ai::filament.settings_overview.ai_sync_partial'))
            ->body(
                __('seo-content-ai::filament.settings_overview.ai_sync_result', [
                    'ok' => $result['ok'],
                    'failed' => $result['failed'],
                ])
                . ($result['messages'] !== [] ? "\n" . implode("\n", $result['messages']) : ''),
            );

        $result['failed'] === 0 ? $notification->success() : $notification->warning();
        $notification->send();
    }

    public function syncConnectionAiModels(int $connectionId, AiModelRouterService $router): void
    {
        $ok = $router->syncModelsForConnection($connectionId);

        $this->refreshAiModelsOverview($router);

        if ($ok) {
            Notification::make()
                ->title(__('seo-content-ai::filament.settings_overview.ai_sync_connection_success'))
                ->body(__('seo-content-ai::filament.settings_overview.ai_sync_connection_body'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_overview.ai_sync_failed'))
            ->body(__('seo-content-ai::filament.settings_overview.ai_sync_failed_body'))
            ->danger()
            ->send();
    }

    public function aiConnectionEditUrl(int $connectionId): string
    {
        return AiConnectionResource::getUrl('edit', ['record' => $connectionId]);
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
