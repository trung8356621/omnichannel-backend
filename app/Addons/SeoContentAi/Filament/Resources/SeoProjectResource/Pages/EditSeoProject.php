<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSeoProject extends EditRecord
{
    protected static string $resource = SeoProjectResource::class;

    public function getTitle(): string
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return __('seo-content-ai::filament.projects.edit_project', [
            'name' => (string) $record->name,
        ]);
    }

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
        $data = SeoProjectResource::normalizeProjectSiteId($data);

        if (! empty($data['month'])) {
            $data['month'] = Carbon::parse($data['month'])->startOfMonth()->format('Y-m-d');
            $data['name'] = SeoProject::defaultNameFromMonth($data['month']);
        }

        $tasksData = $data['tasks_data'] ?? [];
        $projectSiteId = isset($data['site_id']) ? (int) $data['site_id'] : null;
        $sanitized = app(SeoProjectTaskSyncService::class)->sanitizeTasksData($tasksData, $projectSiteId);

        app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($data['month'], $sanitized);

        $data['total_tasks'] = count($sanitized);
        $data['status'] = SeoProject::STATUS_MANUAL;

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
            Actions\Action::make('run_workflow')
                ->label(__('seo-content-ai::filament.projects.run_workflow'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.projects.run_workflow_heading'))
                ->modalDescription(__('seo-content-ai::filament.projects.run_workflow_description'))
                ->action(function (): mixed {
                    /** @var SeoProject $record */
                    $record = $this->getRecord();

                    return SeoProjectResource::dispatchProjectWorkflowRun($record, SeoProjectRun::MODE_FULL);
                }),
            Actions\Action::make('test_run_workflow')
                ->label(__('seo-content-ai::filament.projects.test_run_workflow'))
                ->icon('heroicon-o-beaker')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.projects.test_run_workflow_heading'))
                ->modalDescription(__('seo-content-ai::filament.projects.test_run_workflow_description', [
                    'limit' => SeoProjectWorkflowRunService::TEST_RUN_LIMIT,
                ]))
                ->action(function (): mixed {
                    /** @var SeoProject $record */
                    $record = $this->getRecord();

                    return SeoProjectResource::dispatchProjectWorkflowRun($record, SeoProjectRun::MODE_TEST);
                }),
            Actions\Action::make('view_last_run')
                ->label(__('seo-content-ai::filament.projects.view_last_run'))
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->runs()->exists())
                ->url(fn (): string => SeoProjectResource::getUrl('view-run', [
                    'run' => (int) $this->getRecord()->runs()->latest('id')->value('id'),
                ])),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return SeoProjectResource::getUrl('index');
    }
}
