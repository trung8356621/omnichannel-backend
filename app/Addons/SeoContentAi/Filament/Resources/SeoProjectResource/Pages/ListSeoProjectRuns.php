<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\RunEngine\ContentProjectRunEngine;
use App\Addons\SeoContentAi\Services\SeoProjectRunConsolidationService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowRunService;
use App\Addons\SeoContentAi\Support\RunEngine\ContentProjectRunEngineFeature;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SeoConnectionContext;
use Filament\Actions;
use Filament\Forms;
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
        return $this->project?->notConsolidatedRuns()
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
        $actions = [
            Actions\Action::make('run_workflow')
                ->label(__('seo-content-ai::filament.projects.run_workflow'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->disabled(fn (): bool => ! $this->canStartWorkflowRun())
                ->tooltip(fn (): ?string => $this->canStartWorkflowRun()
                    ? null
                    : __('seo-content-ai::filament.projects.run_workflow_disabled'))
                ->modalHeading(__('seo-content-ai::filament.projects.run_settings_heading'))
                ->modalDescription(fn () => SeoProjectResource::runWorkflowModalDescription($this->project))
                ->form([
                    Forms\Components\Checkbox::make('generate_post_images')
                        ->label(__('seo-content-ai::filament.projects.run_settings_generate_post_images'))
                        ->helperText(__('seo-content-ai::filament.projects.run_settings_generate_post_images_help'))
                        ->default(false),
                    Forms\Components\Checkbox::make('use_php_engine')
                        ->label('PHP Engine (Phase 1)')
                        ->helperText('Bật orchestration PHP cho run này (A/B với legacy JS). Global flag hoặc project allowlist cũng bật.')
                        ->default(fn (): bool => ContentProjectRunEngineFeature::shouldStartWithPhpEngine($this->project)),
                ])
                ->action(function (array $data): void {
                    try {
                        $usePhpEngine = ContentProjectRunEngineFeature::shouldStartWithPhpEngine(
                            $this->project,
                            isset($data['use_php_engine']) ? (bool) $data['use_php_engine'] : null,
                        );

                        $run = SeoProjectResource::createProjectWorkflowRun(
                            $this->project,
                            SeoProjectRun::MODE_FULL,
                            [
                                'generate_post_images' => (bool) ($data['generate_post_images'] ?? false),
                                'use_php_engine' => $usePhpEngine,
                            ],
                        );

                        if ($usePhpEngine) {
                            app(ContentProjectRunEngine::class)->start($run);
                        }

                        SeoConnectionContext::applyUrlDefaults();
                        $url = SeoProjectResource::getUrl('view-run', ['run' => $run->id]);
                        if (! $usePhpEngine) {
                            $url .= '?autorun=1';
                        }

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
                ->modalHeading(__('seo-content-ai::filament.projects.run_settings_heading'))
                ->modalDescription(fn () => SeoProjectResource::runWorkflowModalDescription(
                    $this->project,
                    SeoProjectWorkflowRunService::TEST_RUN_LIMIT,
                ))
                ->form([
                    Forms\Components\Checkbox::make('generate_post_images')
                        ->label(__('seo-content-ai::filament.projects.run_settings_generate_post_images'))
                        ->helperText(__('seo-content-ai::filament.projects.run_settings_generate_post_images_help'))
                        ->default(false),
                    Forms\Components\Checkbox::make('use_php_engine')
                        ->label('PHP Engine (Phase 1)')
                        ->helperText('Bật orchestration PHP cho run test này (A/B với legacy JS).')
                        ->default(fn (): bool => ContentProjectRunEngineFeature::shouldStartWithPhpEngine($this->project)),
                ])
                ->action(function (array $data): void {
                    try {
                        $usePhpEngine = ContentProjectRunEngineFeature::shouldStartWithPhpEngine(
                            $this->project,
                            isset($data['use_php_engine']) ? (bool) $data['use_php_engine'] : null,
                        );

                        $run = SeoProjectResource::createProjectWorkflowRun(
                            $this->project,
                            SeoProjectRun::MODE_TEST,
                            [
                                'generate_post_images' => (bool) ($data['generate_post_images'] ?? false),
                                'use_php_engine' => $usePhpEngine,
                            ],
                        );

                        if ($usePhpEngine) {
                            app(ContentProjectRunEngine::class)->start($run);
                        }

                        SeoConnectionContext::applyUrlDefaults();
                        $url = SeoProjectResource::getUrl('view-run', ['run' => $run->id]);
                        if (! $usePhpEngine) {
                            $url .= '?autorun=1';
                        }

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

        if ($this->project instanceof SeoProject) {
            array_unshift($actions, SeoProjectResource::makeArchiveProjectPageAction($this->project));
        }

        return $actions;
    }
}
