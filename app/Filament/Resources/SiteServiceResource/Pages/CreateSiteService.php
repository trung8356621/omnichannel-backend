<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteServiceResource\Pages;

use Omnichannel\Addons\SearchFoundation\Support\SeoSiteServiceDatabaseConfigurator;
use App\Filament\Resources\SiteServiceResource;
use App\Services\SiteServiceBindingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateSiteService extends CreateRecord
{
    protected static string $resource = SiteServiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        try {
            $data = SeoSiteServiceDatabaseConfigurator::mergeFormSettings($data);

            $binding = app(SiteServiceBindingService::class);
            $data = $binding->normalizeBoundPayload($data);
            $binding->assertBoundPayload($data);

            return SeoSiteServiceDatabaseConfigurator::mutateBeforeSave($data, null);
        } catch (ValidationException $exception) {
            $this->notifyFirstValidationError($exception);

            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Site service create failed before persist', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
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

    protected function afterCreate(): void
    {
        SeoSiteServiceDatabaseConfigurator::runMigrations($this->record);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (QueryException $exception) {
            Log::error('Site service create failed at database layer', [
                'message' => $exception->getMessage(),
                'sql' => $exception->getSql(),
            ]);

            Notification::make()
                ->title(__('site-service.seo_db_config_error_title'))
                ->body(__('site-service.create_database_error'))
                ->danger()
                ->persistent()
                ->send();

            throw $exception;
        }
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
