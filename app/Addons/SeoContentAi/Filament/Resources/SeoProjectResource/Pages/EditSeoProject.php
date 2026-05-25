<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSeoProject extends EditRecord
{
    protected static string $resource = SeoProjectResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        $data['tasks_data'] = app(SeoProjectTaskSyncService::class)->tasksDataFromProject($record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['month'])) {
            $data['month'] = Carbon::parse($data['month'])->startOfMonth()->format('Y-m-d');
            $data['name'] = SeoProject::defaultNameFromMonth($data['month']);
        }

        $tasksData = $data['tasks_data'] ?? [];
        app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($data['month'], $tasksData);

        $data['total_tasks'] = count(
            app(SeoProjectTaskSyncService::class)->sanitizeTasksData($tasksData),
        );

        unset($data['tasks_data']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        $tasksData = $this->form->getState()['tasks_data'] ?? [];

        app(SeoProjectTaskSyncService::class)->sync($record, $tasksData);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return SeoProjectResource::getUrl('index');
    }
}
