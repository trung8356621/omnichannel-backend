<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Concerns\HidesFilamentPageHeader;
use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Services\SeoExtendedProviderConnectionService;
use App\Addons\SeoContentAi\Services\SeoProviderRegistry;
use App\Addons\SeoContentAi\Support\ApiConnectionFormSchema;
use App\Addons\SeoContentAi\Support\ApiConnectionProviders;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page as ResourcePage;

class EditExtendedProviderApiConnection extends ResourcePage implements HasForms
{
    use HidesFilamentPageHeader;
    use InteractsWithForms;

    protected static string $resource = AiConnectionResource::class;

    protected static ?string $slug = 'extended/{provider}/edit';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-api-form';

    protected static bool $shouldRegisterNavigation = false;

    public string $provider = '';

    /** @var array<string, mixed> */
    public ?array $data = [];

    private SeoExtendedProviderConnectionService $extendedConnections;

    private SeoProviderRegistry $providerRegistry;

    public function boot(
        SeoExtendedProviderConnectionService $extendedConnections,
        SeoProviderRegistry $providerRegistry,
    ): void {
        $this->extendedConnections = $extendedConnections;
        $this->providerRegistry = $providerRegistry;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public function mount(string $provider): void
    {
        if (! ApiConnectionProviders::isExtendedProvider($provider)) {
            abort(404);
        }

        $this->provider = $provider;
        $connection = $this->extendedConnections->resolveForUser((int) auth()->id(), $provider);

        $this->form->fill([
            'provider' => $provider,
            'name' => (string) ($connection?->name ?? $this->providerRegistry->label($provider)),
            'extended_api_key' => '',
            'extended_status' => (string) ($connection?->status === 'active' ? 'active' : 'inactive'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(ApiConnectionFormSchema::components(operation: 'edit', lockProvider: true))
            ->statePath('data');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.api_connections.edit_extended_provider', [
            'provider' => $this->providerRegistry->label($this->provider),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label(__('seo-content-ai::filament.api_connections.test_connection'))
                ->action('testConnection'),
        ];
    }

    public function save(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $data = $this->form->getState();
        $this->persistFromForm($data);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.extended_saved'))
            ->success()
            ->send();

        $this->redirect(AiConnectionResource::getUrl('index'));
    }

    public function testConnection(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $data = $this->form->getState();
        $connection = $this->persistFromForm($data);
        $result = $this->extendedConnections->testConnection($connection);

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.test_success')
                : __('seo-content-ai::filament.api_connections.test_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistFromForm(array $data): \App\Addons\SeoContentAi\Models\SeoExtendedProviderConnection
    {
        return $this->extendedConnections->saveForUser((int) auth()->id(), $this->provider, [
            'name' => $data['name'] ?? $this->providerRegistry->label($this->provider),
            'api_key' => $data['extended_api_key'] ?? null,
            'status' => $data['extended_status'] ?? 'inactive',
        ]);
    }

    private function denyMutation(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
            ->danger()
            ->send();
    }
}
