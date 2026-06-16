<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_workflow')
                ->label(__('seo-content-ai::filament.projects.run_workflow'))
                ->icon('heroicon-o-play')
                ->color('success')
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
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.projects.test_run_workflow_heading'))
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
                ->url(fn (): string => SeoProjectResource::getUrl('edit', ['record' => $this->project])),
        ];
    }
}
