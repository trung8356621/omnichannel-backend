<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Services\AiModelRouterService;
use App\Addons\SeoContentAi\Services\SeoOverviewSettingsService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsOverview extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/overview';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Overview';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-overview';

    /** @var array<string, mixed> */
    public array $overviewSettingsData = [];

    /** @var array{connections: list<array<string, mixed>>, total_models: int, last_synced_at: ?string} */
    public array $aiModelsOverview = [
        'connections' => [],
        'total_models' => 0,
        'last_synced_at' => null,
    ];

    public function mount(SeoOverviewSettingsService $settings, AiModelRouterService $router): void
    {
        $raw = $settings->getSettings();

        $this->overviewSettingsData = [
            SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $settings->keywordsToTextarea(
                $raw[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS],
            ),
        ];

        $this->form->fill($this->overviewSettingsData);

        $this->refreshAiModelsOverview($router);

        if (($this->aiModelsOverview['total_models'] ?? 0) === 0) {
            $this->syncAllAiModels($router, silent: true);
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_overview.faq_catch'))
                    ->description(__('seo-content-ai::filament.settings_overview.faq_catch_description'))
                    ->schema([
                        Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS)
                            ->label(__('seo-content-ai::filament.settings_overview.faq_keywords_label'))
                            ->rows(10)
                            ->required()
                            ->columnSpanFull()
                            ->helperText(__('seo-content-ai::filament.settings_overview.faq_keywords_hint')),
                    ]),
            ])
            ->statePath('overviewSettingsData');
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

    public function saveOverviewSettings(SeoOverviewSettingsService $settings): void
    {
        $data = $this->form->getState();
        $raw = (string) ($data[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS] ?? '');

        $settings->saveSettings([
            SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $settings->keywordsFromTextarea($raw),
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_overview.saved'))
            ->success()
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
