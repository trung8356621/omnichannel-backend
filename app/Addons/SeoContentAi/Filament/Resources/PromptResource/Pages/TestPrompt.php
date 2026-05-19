<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use App\Addons\SeoContentAi\Services\WordPressCommentReviewService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class TestPrompt extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = PromptResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.prompt-resource.pages.test-prompt';

    protected static ?string $title = 'Chạy thử Prompt';

    /** @var array<string, string> */
    public array $variableValues = [];

    public ?string $compiledPreview = null;

    public ?string $outputText = null;

    public ?string $errorMessage = null;

    public bool $isRunning = false;

    public ?int $selectedResultId = null;

    public ?int $publishArticleId = null;

    public bool $isPublishingComments = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        foreach (PromptResource::variableDefinitionsForPrompt($this->getPrompt()) as $definition) {
            $this->variableValues[(string) $definition['name']] = '';
        }

        $this->form->fill($this->variableValues);

        $latest = $this->promptResults->first();
        if ($latest !== null) {
            $this->selectResult((int) $latest->id);
        }
    }

    /**
     * @return Collection<int, PromptResult>
     */
    #[Computed]
    public function promptResults(): Collection
    {
        return PromptResult::query()
            ->where('prompt_id', $this->getPrompt()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Bài viết có wp_post_id để thử đăng comment/review lên WordPress.
     *
     * @return Collection<int, SeoArticle>
     */
    #[Computed]
    public function articlesForCommentPublish(): Collection
    {
        return SeoArticle::query()
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->with('site')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    public function publishCommentsToWordPress(WordPressCommentReviewService $publisher): void
    {
        if (! filled($this->outputText)) {
            Notification::make()
                ->title('Chưa có kết quả AI')
                ->body('Chạy thử prompt trước khi đăng lên WordPress.')
                ->warning()
                ->send();

            return;
        }

        if ($this->publishArticleId === null || $this->publishArticleId <= 0) {
            Notification::make()
                ->title('Chọn bài viết đích')
                ->body('Chọn bài viết / sản phẩm đã đồng bộ từ WordPress.')
                ->warning()
                ->send();

            return;
        }

        $article = SeoArticle::query()
            ->with('site')
            ->find($this->publishArticleId);

        if ($article === null) {
            Notification::make()
                ->title('Không tìm thấy bài viết')
                ->danger()
                ->send();

            return;
        }

        $this->isPublishingComments = true;

        try {
            $result = $publisher->publishFromAiOutput($article, (string) $this->outputText);

            $notification = Notification::make()
                ->title($result['success'] ? 'Đăng WordPress thành công' : 'Đăng WordPress thất bại')
                ->body((string) ($result['message'] ?? ''));

            if ($result['success']) {
                $notification->success();
            } else {
                $notification->danger();
            }

            $notification->send();
        } finally {
            $this->isPublishingComments = false;
        }
    }

    public function selectResult(int $resultId): void
    {
        $result = PromptResult::query()
            ->where('prompt_id', $this->getPrompt()->id)
            ->findOrFail($resultId);

        $this->selectedResultId = $resultId;
        $this->applyResultToView($result);
        unset($this->promptResults);
    }

    public function deleteResult(int $resultId): void
    {
        $result = PromptResult::query()
            ->where('prompt_id', $this->getPrompt()->id)
            ->findOrFail($resultId);

        $wasSelected = $this->selectedResultId === $resultId;
        $result->delete();

        unset($this->promptResults);

        if ($wasSelected) {
            $this->clearResultView();

            $latest = $this->promptResults->first();
            if ($latest !== null) {
                $this->selectResult((int) $latest->id);
            }
        }

        Notification::make()
            ->title('Đã xóa lần chạy thử')
            ->success()
            ->send();
    }

    protected function clearResultView(): void
    {
        $this->selectedResultId = null;
        $this->compiledPreview = null;
        $this->outputText = null;
        $this->errorMessage = null;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->getVariableFormSchema())
            ->statePath('variableValues');
    }

    public function getTitle(): string|Htmlable
    {
        $name = (string) ($this->getPrompt()->name ?: $this->getPrompt()->title);

        return 'Chạy thử: ' . $name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit')
                ->label('Sửa Prompt')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => PromptResource::getUrl('edit', ['record' => $this->getRecord()])),
            Actions\Action::make('back')
                ->label('Danh sách')
                ->icon('heroicon-o-arrow-left')
                ->url(PromptResource::getUrl('index')),
        ];
    }

    public function runTest(PromptRunnerService $runner): void
    {
        $this->isRunning = true;
        $this->errorMessage = null;
        $this->outputText = null;
        $this->compiledPreview = null;

        $values = $this->form->getState();
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        try {
            $result = $runner->run($this->getPrompt(), $normalized);

            Notification::make()
                ->title('Chạy thử thành công')
                ->success()
                ->send();

            unset($this->promptResults);
            $this->selectResult((int) $result->id);
        } catch (PromptRunException $exception) {
            $this->errorMessage = $exception->getMessage();

            try {
                $this->compiledPreview = $runner->compilePrompt($this->getPrompt(), $normalized);
            } catch (\Throwable) {
                // Preview optional when compile itself fails.
            }

            Notification::make()
                ->title('Chạy thử thất bại')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            unset($this->promptResults);
            $failed = PromptResult::query()
                ->where('prompt_id', $this->getPrompt()->id)
                ->orderByDesc('id')
                ->first();

            if ($failed !== null) {
                $this->selectResult((int) $failed->id);
            }
        } finally {
            $this->isRunning = false;
        }
    }

    protected function applyResultToView(PromptResult $result): void
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $this->compiledPreview = (string) ($snapshot['compiled_prompt'] ?? '');

        $savedVariables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
        foreach ($savedVariables as $key => $value) {
            $name = (string) $key;
            if (array_key_exists($name, $this->variableValues)) {
                $this->variableValues[$name] = is_string($value) ? $value : (string) $value;
            }
        }

        $this->form->fill($this->variableValues);

        if ($result->status === 'completed') {
            $this->outputText = (string) ($result->output_text ?? '');
            $this->errorMessage = null;
        } elseif ($result->status === 'failed') {
            $this->outputText = null;
            $this->errorMessage = (string) ($result->error_message ?? 'Chạy thử thất bại.');
        } else {
            $this->outputText = null;
            $this->errorMessage = null;
        }
    }

    public function resultSummary(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];

        foreach (['post_title', 'focus_keyword'] as $preferred) {
            if (filled($variables[$preferred] ?? null)) {
                return (string) $variables[$preferred];
            }
        }

        foreach ($variables as $value) {
            if (filled($value)) {
                return mb_strlen((string) $value) > 48
                    ? mb_substr((string) $value, 0, 48) . '…'
                    : (string) $value;
            }
        }

        if ($result->status === 'completed' && filled($result->output_text)) {
            $text = trim((string) $result->output_text);

            return mb_strlen($text) > 48 ? mb_substr($text, 0, 48) . '…' : $text;
        }

        return '#' . $result->id;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected function getVariableFormSchema(): array
    {
        if (! $this->record) {
            return [];
        }

        $definitions = PromptResource::variableDefinitionsForPrompt($this->getPrompt());

        if ($definitions === []) {
            return [
                Forms\Components\Placeholder::make('no_variables')
                    ->label('')
                    ->content('Prompt này không khai báo biến {{tên}}. Bạn có thể chạy thử trực tiếp.'),
            ];
        }

        return collect($definitions)
            ->map(function (array $definition): Forms\Components\Textarea {
                $field = Forms\Components\Textarea::make((string) $definition['name'])
                    ->label((string) $definition['label'])
                    ->rows(2)
                    ->columnSpanFull();

                if (filled($definition['description'] ?? null)) {
                    $field->helperText((string) $definition['description']);
                }

                return $field;
            })
            ->all();
    }

    protected function getPrompt(): SeoPrompt
    {
        /** @var SeoPrompt $record */
        $record = $this->getRecord();

        return $record;
    }
}
