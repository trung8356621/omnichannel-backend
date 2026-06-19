<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\SeoProjectRunConsolidationService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class ListSeoProjectRuns extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.list-project-runs';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record;

    public ?SeoProject $project = null;

    public function mount(int|string $record): void
    {
        self::authorizeResourceAccess();

        $this->record = (int) $record;
        $this->project = SeoProjectResource::getEloquentQuery()
            ->with(['site', 'user'])
            ->findOrFail($this->record);

        abort_unless(SeoAccessControl::canAccessContentProjectRun($this->project), 403);

        app(SeoProjectRunConsolidationService::class)->maybeConsolidate($this->project);
        $this->project->refresh();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Run history - '.(string) $this->project?->name;
    }

    public function getRuns()
    {
        return $this->project?->runs()
            ->with('user')
            ->latest('id')
            ->get() ?? collect();
    }

    public function canStartWorkflowRun(): bool
    {
        if ($this->project === null) {
            return false;
        }

        return app(SeoProjectRunConsolidationService::class)->hasRunnablePendingTasks($this->project);
    }

    public function isProjectFullyCompleted(): bool
    {
        if ($this->project === null) {
            return false;
        }

        return app(SeoProjectRunConsolidationService::class)->isProjectFullyCompleted($this->project);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_workflow')
                ->label(__('seo-content-ai::filament.projects.run_workflow'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->disabled(fn (): bool => ! $this->canStartWorkflowRun())
                ->tooltip(fn (): ?string => $this->canStartWorkflowRun()
                    ? null
                    : __('seo-content-ai::filament.projects.run_workflow_disabled'))
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.projects.run_workflow_heading'))
                ->modalDescription(fn () => SeoProjectResource::runWorkflowModalDescription($this->project))
                ->action(function (): void {
                    try {
                        $run = SeoProjectResource::createProjectWorkflowRun(
                            $this->project,
                            SeoProjectRun::MODE_FULL,
                        );
                        $url = SeoProjectResource::getUrl('view-run', ['run' => $run->id]).'?autorun=1';

                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_started'))
                            ->body(__('seo-content-ai::filament.projects.run_started_new_tab'))
                            ->success()
                            ->send();

                        $this->js('window.open('.json_encode($url).', "_blank")');
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('test_run_workflow')
                ->label(__('seo-content-ai::filament.projects.test_run_workflow'))
                ->icon('heroicon-o-beaker')
                ->color('warning')
                ->disabled(fn (): bool => ! $this->canStartWorkflowRun())
                ->tooltip(fn (): ?string => $this->canStartWorkflowRun()
                    ? null
                    : __('seo-content-ai::filament.projects.run_workflow_disabled'))
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.projects.test_run_workflow_heading', [
                    'limit' => SeoProjectWorkflowRunService::TEST_RUN_LIMIT,
                ]))
                ->modalDescription(fn () => SeoProjectResource::runWorkflowModalDescription(
                    $this->project,
                    SeoProjectWorkflowRunService::TEST_RUN_LIMIT,
                ))
                ->action(function (): void {
                    try {
                        $run = SeoProjectResource::createProjectWorkflowRun(
                            $this->project,
                            SeoProjectRun::MODE_TEST,
                        );
                        $url = SeoProjectResource::getUrl('view-run', ['run' => $run->id]).'?autorun=1';

                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_started'))
                            ->body(__('seo-content-ai::filament.projects.run_started_new_tab'))
                            ->success()
                            ->send();

                        $this->js('window.open('.json_encode($url).', "_blank")');
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('back_to_project')
                ->label(__('seo-content-ai::filament.projects.back_to_project'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => SeoProjectResource::projectRecordUrl($this->project)),
        ];
    }
}
