<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\TaskResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\TaskResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use App\Addons\SeoContentAi\Services\TaskWorkflowTestRunner;
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

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
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
        /** @var SeoTask $task */
        $task = $this->getRecord();

        return 'Chạy thử: ' . (string) $task->name;
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

        $state = $this->form->getState();
        $articleId = filled($state['article_id'] ?? null) ? (int) $state['article_id'] : null;
        $query = trim((string) ($state['title_or_keyword'] ?? ''));

        try {
            if ($articleId === null && $query === '') {
                throw new \InvalidArgumentException('Chọn bài viết hoặc nhập tiêu đề / từ khóa.');
            }

            $context = $resolver->resolve(
                $articleId,
                $query !== '' ? $query : null,
                $query !== '' ? $query : null,
                function (Builder $builder): void {
                    $this->applyUserScopeToArticles($builder);
                },
            );
            $this->resolvedContext = $context->toArray();

            /** @var SeoTask $task */
            $task = $this->getRecord();
            $this->stepResults = $runner->run($task, $context);

            $failed = collect($this->stepResults)->where('status', 'failed')->count();

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
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            Notification::make()
                ->title('Chạy thử thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isRunning = false;
        }
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
}
