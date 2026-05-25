<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\TaskResource\Pages;

use App\Addons\SeoContentAi\Exceptions\AiModelsNotReadyException;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\TaskResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Models\TaskTestResult;
use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use App\Addons\SeoContentAi\Services\TaskWorkflowTestRunner;
use App\Addons\SeoContentAi\Support\TaskTestContext;
use App\Models\Site;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class TestTask extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = TaskResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.task-resource.pages.test-task';

    protected static ?string $title = 'Chạy thử quy trình';

    /** @var array{article_id: ?int, title_or_keyword: ?string} */
    public array $testInput = [
        'article_id' => null,
        'title_or_keyword' => '',
    ];

    /** @var array<string, mixed>|null */
    public ?array $resolvedContext = null;

    /** @var list<array<string, mixed>> */
    public array $stepResults = [];

    public ?string $errorMessage = null;

    public bool $isRunning = false;

    public ?int $runningStepIndex = null;

    public ?int $selectedResultId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->form->fill($this->testInput);

        $latest = $this->taskTestResults->first();
        if ($latest !== null) {
            $this->selectResult((int) $latest->id);
        }
    }

    /**
     * @return Collection<int, TaskTestResult>
     */
    #[Computed]
    public function taskTestResults(): Collection
    {
        return TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('article_id')
                    ->label('Bài viết')
                    ->placeholder('Chọn bài viết từ danh sách…')
                    ->searchable()
                    ->searchPrompt('Tìm theo tiêu đề hoặc ID…')
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchArticles($search))
                    ->getOptionLabelUsing(fn ($value): ?string => $this->articleOptionLabel(
                        is_numeric($value) ? (int) $value : null,
                    ))
                    ->live()
                    ->helperText('Mọi domain thuộc tài khoản của bạn. Khi đã chọn bài, ô tiêu đề/từ khóa sẽ bị bỏ qua.'),
                Forms\Components\TextInput::make('title_or_keyword')
                    ->label('Tiêu đề hoặc từ khóa')
                    ->maxLength(500)
                    ->placeholder('Nhập tiêu đề bài viết hoặc focus keyword')
                    ->disabled(fn (Get $get): bool => filled($get('article_id')))
                    ->helperText('Dùng khi chưa chọn bài: tìm bài có sẵn theo tiêu đề trước, sau đó từ khóa; không khớp thì tạo bài mới.'),
            ])
            ->statePath('testInput');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Chạy thử: ' . (string) $this->getTask()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('builder')
                ->label('Mở Builder')
                ->icon('heroicon-o-squares-2x2')
                ->url(fn (): string => TaskResource::getUrl('builder', ['record' => $this->getRecord()])),
            Actions\Action::make('back')
                ->label('Danh sách')
                ->icon('heroicon-o-arrow-left')
                ->url(TaskResource::getUrl('index')),
        ];
    }

    public function runTest(
        TaskTestInputResolver $resolver,
        TaskWorkflowTestRunner $runner,
    ): void {
        $this->isRunning = true;
        $this->errorMessage = null;
        $this->resolvedContext = null;
        $this->stepResults = [];
        $this->runningStepIndex = null;

        $state = $this->form->getState();
        $startedAt = now();

        try {
            $context = $this->resolveContext($resolver, $state);
            $this->resolvedContext = $context->toArray();
            $this->stepResults = $runner->run($this->getTask(), $context);

            $failed = collect($this->stepResults)->where('status', 'failed')->count();
            $status = $failed > 0 ? 'failed' : 'completed';

            $result = $this->persistResult($state, $status, $startedAt);
            $this->selectedResultId = (int) $result->id;
            unset($this->taskTestResults);

            $notification = Notification::make()
                ->title($failed > 0 ? 'Chạy thử xong (có lỗi)' : 'Chạy thử thành công')
                ->body($context->summary);

            if ($failed > 0) {
                $notification->warning();
            } else {
                $notification->success();
            }

            $notification->send();
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            Notification::make()
                ->title('Không thể chạy thử')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } catch (AiModelsNotReadyException $exception) {
            Notification::make()
                ->title('Cần đồng bộ model AI')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            $this->redirect($exception->overviewUrl(), navigate: true);
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            $result = $this->persistResult($state, 'failed', $startedAt);
            $this->selectedResultId = (int) $result->id;
            unset($this->taskTestResults);

            Notification::make()
                ->title('Chạy thử thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isRunning = false;
        }
    }

    public function rerunStep(int $stepIndex, TaskWorkflowTestRunner $runner): void
    {
        if ($this->resolvedContext === null || $this->stepResults === []) {
            Notification::make()
                ->title('Chưa có kết quả')
                ->body('Chạy thử quy trình trước hoặc chọn một lần chạy trong lịch sử.')
                ->warning()
                ->send();

            return;
        }

        if (! isset($this->stepResults[$stepIndex])) {
            return;
        }

        $nodeId = (string) ($this->stepResults[$stepIndex]['node_id'] ?? '');
        if ($nodeId === '') {
            return;
        }

        $this->runningStepIndex = $stepIndex;

        try {
            $context = TaskTestContext::fromArray($this->resolvedContext);
            $priorSteps = array_slice($this->stepResults, 0, $stepIndex);
            $this->stepResults[$stepIndex] = $runner->runSingleStep(
                $this->getTask(),
                $context,
                $nodeId,
                $priorSteps,
            );

            $this->syncSelectedResult();

            $step = $this->stepResults[$stepIndex];
            $status = (string) ($step['status'] ?? '');

            $notification = Notification::make()
                ->title('Đã chạy lại bước #' . ($stepIndex + 1));

            if ($status === 'failed') {
                $notification->body((string) ($step['message'] ?? 'Bước thất bại.'))->warning();
            } elseif ($status === 'skipped') {
                $notification->body((string) ($step['message'] ?? 'Bước được bỏ qua.'))->info();
            } else {
                $notification->body((string) ($step['message'] ?? 'Bước hoàn tất.'))->success();
            }

            $notification->send();
        } catch (AiModelsNotReadyException $exception) {
            Notification::make()
                ->title('Cần đồng bộ model AI')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            $this->redirect($exception->overviewUrl(), navigate: true);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Chạy lại bước thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->runningStepIndex = null;
        }
    }

    public function selectResult(int $resultId): void
    {
        $result = TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->findOrFail($resultId);

        $this->selectedResultId = $resultId;
        $this->applyResultToView($result);
        unset($this->taskTestResults);
    }

    public function deleteResult(int $resultId): void
    {
        $result = TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->findOrFail($resultId);

        $wasSelected = $this->selectedResultId === $resultId;
        $result->delete();

        unset($this->taskTestResults);

        if ($wasSelected) {
            $this->clearResultView();

            $latest = $this->taskTestResults->first();
            if ($latest !== null) {
                $this->selectResult((int) $latest->id);
            }
        }

        Notification::make()
            ->title('Đã xóa lần chạy thử')
            ->success()
            ->send();
    }

    public function resultSummary(TaskTestResult $result): string
    {
        $context = is_array($result->resolved_context) ? $result->resolved_context : [];

        if (filled($context['summary'] ?? null)) {
            $summary = (string) $context['summary'];

            return mb_strlen($summary) > 48 ? mb_substr($summary, 0, 48) . '…' : $summary;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $query = trim((string) ($snapshot['title_or_keyword'] ?? ''));

        if ($query !== '') {
            return mb_strlen($query) > 48 ? mb_substr($query, 0, 48) . '…' : $query;
        }

        return '#' . $result->id;
    }

    public function resultStepLabel(TaskTestResult $result): string
    {
        $steps = is_array($result->step_results) ? $result->step_results : [];
        $total = count($steps);
        $failed = collect($steps)->where('status', 'failed')->count();

        if ($total === 0) {
            return '0 bước';
        }

        return $failed > 0
            ? sprintf('%d bước · %d lỗi', $total, $failed)
            : sprintf('%d bước', $total);
    }

    protected function clearResultView(): void
    {
        $this->selectedResultId = null;
        $this->resolvedContext = null;
        $this->stepResults = [];
        $this->errorMessage = null;
    }

    protected function applyResultToView(TaskTestResult $result): void
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        $this->testInput = [
            'article_id' => filled($snapshot['article_id'] ?? null) ? (int) $snapshot['article_id'] : null,
            'title_or_keyword' => (string) ($snapshot['title_or_keyword'] ?? ''),
        ];
        $this->form->fill($this->testInput);

        $this->resolvedContext = is_array($result->resolved_context) ? $result->resolved_context : null;
        $this->stepResults = is_array($result->step_results) ? $result->step_results : [];
        $this->errorMessage = $result->status === 'failed' && $this->stepResults === []
            ? (string) ($result->error_message ?? 'Chạy thử thất bại.')
            : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveContext(TaskTestInputResolver $resolver, array $state): TaskTestContext
    {
        $articleId = filled($state['article_id'] ?? null) ? (int) $state['article_id'] : null;
        $query = trim((string) ($state['title_or_keyword'] ?? ''));

        if ($articleId === null && $query === '') {
            throw new \InvalidArgumentException('Chọn bài viết hoặc nhập tiêu đề / từ khóa.');
        }

        return $resolver->resolve(
            $articleId,
            $query !== '' ? $query : null,
            $query !== '' ? $query : null,
            function (Builder $builder): void {
                $this->applyUserScopeToArticles($builder);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persistResult(array $state, string $status, \DateTimeInterface $startedAt): TaskTestResult
    {
        return TaskTestResult::query()->create([
            'task_id' => $this->getTask()->id,
            'user_id' => auth()->id(),
            'status' => $status,
            'input_snapshot' => [
                'article_id' => filled($state['article_id'] ?? null) ? (int) $state['article_id'] : null,
                'title_or_keyword' => (string) ($state['title_or_keyword'] ?? ''),
            ],
            'resolved_context' => $this->resolvedContext,
            'step_results' => $this->stepResults,
            'error_message' => $this->errorMessage,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    private function syncSelectedResult(): void
    {
        if ($this->selectedResultId === null) {
            return;
        }

        $result = TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->find($this->selectedResultId);

        if ($result === null) {
            return;
        }

        $failed = collect($this->stepResults)->where('status', 'failed')->count();

        $result->update([
            'status' => $failed > 0 ? 'failed' : 'completed',
            'resolved_context' => $this->resolvedContext,
            'step_results' => $this->stepResults,
            'finished_at' => now(),
        ]);

        unset($this->taskTestResults);
    }

    /**
     * @return array<int, string>
     */
    private function searchArticles(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $query = $this->articlesQuery()->with('site');

        $query->where(function (Builder $inner) use ($search): void {
            $inner->where('title', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');

            if (ctype_digit($search)) {
                $inner->orWhere('id', (int) $search);
            }
        });

        return $query
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (SeoArticle $article): array => [
                $article->id => $this->formatArticleOptionLabel($article),
            ])
            ->all();
    }

    private function articleOptionLabel(?int $articleId): ?string
    {
        if ($articleId === null || $articleId <= 0) {
            return null;
        }

        $article = $this->articlesQuery()->with('site')->find($articleId);

        return $article instanceof SeoArticle
            ? $this->formatArticleOptionLabel($article)
            : null;
    }

    private function formatArticleOptionLabel(SeoArticle $article): string
    {
        $domain = trim((string) ($article->site?->domain ?? ''));
        $domainLabel = $domain !== '' ? $domain : '—';

        return sprintf('#%d · %s (%s)', $article->id, (string) $article->title, $domainLabel);
    }

    private function articlesQuery(): Builder
    {
        return ArticleResource::getEloquentQuery();
    }

    private function applyUserScopeToArticles(Builder $query): void
    {
        if (auth()->user()?->role === 'admin') {
            return;
        }

        $query->whereIn(
            'site_id',
            Site::query()->where('user_id', auth()->id())->select('id'),
        );
    }

    private function getTask(): SeoTask
    {
        /** @var SeoTask $record */
        $record = $this->getRecord();

        return $record;
    }
}
