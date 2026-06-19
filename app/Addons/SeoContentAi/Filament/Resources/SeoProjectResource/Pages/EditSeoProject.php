<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoEditRecord;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\SeoProjectArticleOwnerSyncService;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Carbon\Carbon;
use Filament\Actions;

class EditSeoProject extends SeoEditRecord
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
        abort_if(SeoAccessControl::isContentManager(), 403);

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
        $data['status'] = $this->getRecord()->status === SeoProject::STATUS_APPROVED
            ? SeoProject::STATUS_APPROVED
            : SeoProject::STATUS_MANUAL;

        unset($data['tasks_data']);

        return $data;
    }

    protected function afterSave(): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        /** @var SeoProject $record */
        $record = $this->getRecord();
        $monthChanged = $record->wasChanged('month');
        $record = $record->fresh(['tasks']);

        $tasksData = $this->form->getState()['tasks_data'] ?? [];
        $syncService = app(SeoProjectTaskSyncService::class);
        $projectSiteId = $record->site_id !== null ? (int) $record->site_id : null;

        $incomingSignature = $syncService->tasksSignature($tasksData, $projectSiteId);
        $existingSignature = $syncService->tasksSignature(
            $syncService->tasksDataFromProject($record),
            $projectSiteId,
        );

        if ($monthChanged || $incomingSignature !== $existingSignature) {
            $syncService->sync($record, $tasksData);

            return;
        }

        app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($record);
    }

    protected function shouldDisableSeoFormSave(): bool
    {
        return false;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view_runs')
                ->label(__('seo-content-ai::filament.projects.view_runs'))
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->visible(fn (): bool => SeoAccessControl::canAccessContentProjectRun($this->getRecord()))
                ->url(fn (): string => SeoProjectResource::getRunHistoryUrl($this->getRecord())),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => SeoAccessControl::canMutateContentProjects()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return SeoProjectResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
