<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteServiceResource\Pages;

use Omnichannel\Addons\SearchFoundation\Support\SeoSiteServiceDatabaseConfigurator;
use App\Filament\Resources\SiteServiceResource;
use App\Services\SiteServiceBindingService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditSiteService extends EditRecord
{
    protected static string $resource = SiteServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => SiteServiceResource::canDelete($this->record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SeoSiteServiceDatabaseConfigurator::hydrateFormSettings($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            $data = SeoSiteServiceDatabaseConfigurator::mergeFormSettings($data);

            $binding = app(SiteServiceBindingService::class);
            $data = $binding->normalizeBoundPayload($data);
            $binding->assertBoundPayload($data);

            return SeoSiteServiceDatabaseConfigurator::mutateBeforeSave($data, $this->record);
        } catch (ValidationException $exception) {
            $this->notifyFirstValidationError($exception);

            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Site service update failed before persist', [
                'site_service_id' => $this->record->getKey(),
                'message' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title(__('site-service.seo_db_config_error_title'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw $exception;
        }
    }

    protected function afterSave(): void
    {
        SeoSiteServiceDatabaseConfigurator::runMigrations($this->record->fresh());
    }

    protected function onValidationError(ValidationException $exception): void
    {
        $this->notifyFirstValidationError($exception);
    }

    private function notifyFirstValidationError(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->filter()->first();

        if (! is_string($message) || $message === '') {
            return;
        }

        Log::warning('Site service form validation failed', [
            'site_service_id' => $this->record->getKey(),
            'errors' => $exception->errors(),
        ]);

        Notification::make()
            ->title(__('site-service.seo_db_config_error_title'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();
    }
}
