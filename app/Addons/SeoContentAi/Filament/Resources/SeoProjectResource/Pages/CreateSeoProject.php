<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSeoProject extends CreateRecord
{
    protected static string $resource = SeoProjectResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tasksData = $this->form->getState()['tasks_data'] ?? [];

        /** @var SeoProject $project */
        $project = static::getModel()::create($data);

        app(SeoProjectTaskSyncService::class)->sync($project, $tasksData);

        return $project;
    }

    protected function getRedirectUrl(): string
    {
        return SeoProjectResource::getUrl('index');
    }
}
