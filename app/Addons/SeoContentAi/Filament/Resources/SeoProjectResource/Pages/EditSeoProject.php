<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSeoProject extends EditRecord
{
    protected static string $resource = SeoProjectResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (SeoAccessControl::isContentManager()) {
            $this->form->disabled();
        }
    }

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

        $tasksData = $this->form->getState()['tasks_data'] ?? [];

        app(SeoProjectTaskSyncService::class)->sync($record, $tasksData);
    }

    protected function getFormActions(): array
    {
        if (SeoAccessControl::isContentManager()) {
            return [
                $this->getCancelFormAction(),
            ];
        }

        return parent::getFormActions();
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
