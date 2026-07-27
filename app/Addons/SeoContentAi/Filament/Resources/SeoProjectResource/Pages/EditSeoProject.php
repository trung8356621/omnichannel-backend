<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoEditRecord;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\SyncContentProjectItemsCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectCommandBus;
use App\Addons\SeoContentAi\Services\SeoProjectArticleOwnerSyncService;
use App\Addons\SeoContentAi\Services\SeoProjectTaskMoveService;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EditSeoProject extends SeoEditRecord
{
    protected static string $resource = SeoProjectResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SeoProject $project */
        $project = $this->getRecord();
        if ($project->isArchive() || $project->isProjectArchived()) {
            $this->redirect(SeoProjectResource::getUrl('index'));
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

        /** @var SeoProject $record */
        $record = $this->getRecord();

        $data = SeoProjectResource::normalizeProjectSiteId($data);

        $tasksData = $data['tasks_data'] ?? [];
        unset($data['unassigned_staff_ids'], $data['assign_from_unassigned']);

        if (! empty($data['month'])) {
            $data['month'] = Carbon::parse($data['month'])->startOfMonth()->format('Y-m-d');
            $data['name'] = SeoProject::defaultNameFromMonth($data['month']);
        }

        if (! $record->isArchive()) {
            $siteId = isset($data['site_id']) ? (int) $data['site_id'] : (int) ($record->site_id ?? 0);
            $month = (string) ($data['month'] ?? $record->month?->format('Y-m-d') ?? '');
            if ($siteId > 0 && $month !== '' && SeoProjectResource::monthlyProjectExistsForSiteMonth(
                $siteId,
                $month,
                (int) $record->getKey(),
            )) {
                throw ValidationException::withMessages([
                    'data.month' => __('seo-content-ai::filament.projects.month_already_exists'),
                ]);
            }
        }

        $projectSiteId = isset($data['site_id']) ? (int) $data['site_id'] : null;
        $sanitized = app(SeoProjectTaskSyncService::class)->sanitizeTasksData($tasksData, $projectSiteId);

        app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($data['month'], $sanitized);

        $data['total_tasks'] = count($sanitized);
        $data['status'] = $record->status === SeoProject::STATUS_APPROVED
            ? SeoProject::STATUS_APPROVED
            : SeoProject::STATUS_MANUAL;

        unset($data['tasks_data']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        /** @var SeoProject $project */
        $project = $record;
        $projectId = (int) $project->getKey();
        $siteId = (int) ($data['site_id'] ?? $project->site_id ?? 0);
        $authUserId = auth()->id() !== null ? (int) auth()->id() : null;
        $bus = app(ContentProjectCommandBus::class);
        $actor = ActorContext::user($authUserId, $siteId > 0 ? $siteId : null);
        $syncService = app(SeoProjectTaskSyncService::class);

        $originalMonth = $project->month?->format('Y-m-d');
        $newMonth = (string) ($data['month'] ?? $originalMonth ?? '');
        $monthChanged = $originalMonth !== null && $newMonth !== '' && $originalMonth !== $newMonth;

        $updateResult = $bus->dispatch(
            new UpdateContentProjectCommand($projectId, $data),
            $actor,
        );

        if (! $updateResult->success) {
            if ($updateResult->code === ContentProjectActionCodes::VALIDATION_FAILED) {
                throw ValidationException::withMessages([
                    'data' => $updateResult->message,
                ]);
            }

            throw new RuntimeException($updateResult->message);
        }

        $tasksData = $this->form->getState()['tasks_data'] ?? [];
        $projectSiteId = isset($data['site_id']) ? (int) $data['site_id'] : ($project->site_id !== null ? (int) $project->site_id : null);
        $projectForCompare = $project->fresh(['tasks']) ?? $project;

        $incomingSignature = $syncService->tasksSignature($tasksData, $projectSiteId);
        $existingSignature = $syncService->tasksSignature(
            $syncService->tasksDataFromProject($projectForCompare),
            $projectSiteId,
        );

        if ($monthChanged || $incomingSignature !== $existingSignature) {
            $syncResult = $bus->dispatch(
                new SyncContentProjectItemsCommand($projectId, $tasksData),
                $actor,
            );

            if (! $syncResult->success) {
                throw new RuntimeException($syncResult->message);
            }
        } else {
            app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($projectForCompare);
        }

        /** @var SeoProject $fresh */
        $fresh = SeoProject::query()->findOrFail($projectId);

        return $fresh;
    }

    protected function getHeaderActions(): array
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return [
            Actions\ActionGroup::make([
                SeoProjectResource::makeArchiveProjectPageAction($record),
                Actions\Action::make('publishing_queue')
                    ->label(__('seo-content-ai::filament.projects.publishing_queue'))
                    ->icon('heroicon-o-calendar-days')
                    ->color('primary')
                    ->url(fn (): string => SeoProjectResource::getPublishingQueueUrl($this->getRecord())),
                Actions\Action::make('view_runs')
                    ->label(__('seo-content-ai::filament.projects.view_runs'))
                    ->icon('heroicon-o-queue-list')
                    ->color('gray')
                    ->visible(fn (): bool => SeoAccessControl::canAccessContentProjectRun($this->getRecord()))
                    ->url(fn (): string => SeoProjectResource::getRunHistoryUrl($this->getRecord())),
                Actions\DeleteAction::make()
                    ->visible(fn (): bool => SeoAccessControl::canMutateContentProjects())
                    ->requiresConfirmation()
                    ->modalHeading(__('seo-content-ai::filament.projects.delete_heading'))
                    ->modalDescription(__('seo-content-ai::filament.projects.delete_description'))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.delete_submit'))
                    ->successNotification(null)
                    ->using(function (SeoProject $record): bool {
                        try {
                            app(SeoProjectTaskMoveService::class)->deleteProject($record);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.projects.delete_completed'))
                                ->body(__('seo-content-ai::filament.projects.delete_completed_body'))
                                ->success()
                                ->send();

                            return true;
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title(__('seo-content-ai::filament.projects.delete_blocked', [
                                    'name' => (string) $record->name,
                                ]))
                                ->body($exception->validator->errors()->first() ?: $exception->getMessage())
                                ->danger()
                                ->send();

                            throw $exception;
                        } catch (\Throwable $exception) {
                            RuntimeLogger::report($exception, ['project_id' => (int) $record->getKey()]);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.projects.delete_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            throw $exception;
                        }
                    }),
            ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->label(__('seo-content-ai::filament.projects.more_actions'))
                ->button()
                ->color('gray'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return SeoProjectResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
