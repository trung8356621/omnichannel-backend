<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;

use App\Addons\SeoContentAi\Exceptions\AiModelsNotReadyException;
use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Services\AiModelsReadinessService;
use App\Addons\SeoContentAi\Filament\Pages\MediaImageEditor;
use App\Addons\SeoContentAi\Filament\Pages\MediaLibrary;
use App\Addons\SeoContentAi\Services\SeoMediaImageEditorResolverService;
use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoPromptPart;
use App\Addons\SeoContentAi\Services\PromptLoaiSanPhamOptionsService;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use App\Addons\SeoContentAi\Services\PromptTestPublishService;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryImageActionService;
use App\Addons\SeoContentAi\Services\WordPressCommentReviewService;
use App\Addons\SeoContentAi\Support\PromptLoaiSanPhamVariable;
use App\Addons\SeoContentAi\Support\PromptMediaPersistContext;
use App\Addons\SeoContentAi\Support\PromptTokenUsage;
use App\Addons\SeoContentAi\Support\Utf8Sanitizer;
use App\Models\Site;
use Filament\Forms\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
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

    /** Nhãn token của lần chạy đang xem, VD: "12.450 token". */
    public ?string $tokenUsageLabel = null;

    /** Model API thực tế (slug) của lần chạy đang xem. */
    public ?string $lastRawModelUsed = null;

    public bool $isRunning = false;

    public ?int $selectedResultId = null;

    public ?int $publishArticleId = null;

    public bool $isPublishingTest = false;

    /** Kết quả bước trước trong chuỗi test (gán vào {{PARENT_RESULT}} cho prompt con). */
    public ?string $chainLastOutput = null;

    public bool $chainParentCompleted = false;

    public int $chainSubTasksCompleted = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        if (! $this->ensureAiModelsReadyOrRedirect()) {
            return;
        }

        $this->syncVariableValueKeys();

        $this->form->fill($this->variableValues);

        $latest = $this->promptResults->first();
        if ($latest !== null) {
            $this->selectResult((int) $latest->id);
        } else {
            $this->refreshCompiledPreview();
        }
    }

    #[Computed]
    public function promptUsesInput(): bool
    {
        return PromptResource::promptUsesInputVariable($this->getPrompt());
    }

    #[Computed]
    public function promptUsesLoaiSanPham(): bool
    {
        return PromptLoaiSanPhamVariable::usesInPrompt($this->getPrompt());
    }

    #[Computed]
    public function hasDependentSubTasks(): bool
    {
        return app(PromptRunnerService::class)->hasDependentSubTasks($this->getPrompt());
    }

    /**
     * @return list<array{index: int, name: string}>
     */
    #[Computed]
    public function dependentSubTaskSteps(): array
    {
        return app(PromptRunnerService::class)
            ->getDependentSubTaskParts($this->getPrompt())
            ->map(static fn (SeoPromptPart $part, int $index): array => [
                'index' => $index,
                'name' => filled($part->name) ? (string) $part->name : ('Prompt con ' . ($index + 1)),
            ])
            ->values()
            ->all();
    }

    public function hasMoreSubTasksToRun(): bool
    {
        if (! $this->usesStepByStepChain()) {
            return false;
        }

        return $this->chainParentCompleted
            && $this->chainSubTasksCompleted < count($this->dependentSubTaskSteps);
    }

    public function usesStepByStepChain(): bool
    {
        return $this->hasDependentSubTasks && ! $this->isImageToolPrompt();
    }

    public function nextSubTaskButtonLabel(): string
    {
        $steps = $this->dependentSubTaskSteps;
        $idx = $this->chainSubTasksCompleted;

        return 'Chạy prompt con: ' . ($steps[$idx]['name'] ?? ('Bước ' . ($idx + 1)));
    }

    /**
     * @return array<int, array{name: string, label: string, description: ?string}>
     */
    #[Computed]
    public function variableDefinitions(): array
    {
        return PromptResource::variableDefinitionsForPrompt($this->getPrompt());
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

    public function publishTest(string $mode, PromptTestPublishService $skeletonPublisher, WordPressCommentReviewService $reviewPublisher): void
    {
        if (! filled($this->outputText)) {
            Notification::make()
                ->title('Chưa có kết quả AI')
                ->body('Chạy thử prompt trước khi đăng.')
                ->warning()
                ->send();

            return;
        }

        $article = $this->resolvePublishTargetArticle();
        if ($article === null) {
            return;
        }

        $variables = $this->normalizedVariableValues();

        $this->isPublishingTest = true;

        try {
            $result = match ($mode) {
                'skeleton' => $skeletonPublisher->publishSkeleton($article, (string) $this->outputText, $variables),
                'article' => $skeletonPublisher->publishArticle($article, (string) $this->outputText, $variables),
                'reviews' => $reviewPublisher->publishFromAiOutput($article, (string) $this->outputText),
                default => ['success' => false, 'message' => 'Hành động không hợp lệ.'],
            };

            $notification = Notification::make()
                ->title($result['success'] ? 'Thành công' : 'Thất bại')
                ->body((string) ($result['message'] ?? ''));

            $result['success'] ? $notification->success() : $notification->danger();
            $notification->send();
        } finally {
            $this->isPublishingTest = false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function normalizedVariableValues(): array
    {
        $values = $this->form->getState();
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        if ($this->promptUsesLoaiSanPham) {
            $normalized = PromptLoaiSanPhamVariable::mergeIntoVariables($normalized);

            return Utf8Sanitizer::variables(PromptLoaiSanPhamVariable::withAliases($normalized));
        }

        return Utf8Sanitizer::variables($normalized);
    }

    private function resolvePublishTargetArticle(): ?SeoArticle
    {
        if ($this->publishArticleId === null || $this->publishArticleId <= 0) {
            Notification::make()
                ->title('Chọn bài viết đích')
                ->body('Chọn bài viết / sản phẩm đã đồng bộ từ WordPress.')
                ->warning()
                ->send();

            return null;
        }

        $query = SeoArticle::query()->with('site')->whereKey($this->publishArticleId);

        if (auth()->user()?->role !== 'admin') {
            $query->whereIn(
                'site_id',
                \App\Models\Site::query()->where('user_id', auth()->id())->select('id'),
            );
        }

        $article = $query->first();

        if ($article === null) {
            Notification::make()
                ->title('Không tìm thấy bài viết')
                ->danger()
                ->send();
        }

        return $article;
    }

    public function selectResult(int $resultId): void
    {
        $result = PromptResult::query()
            ->where('prompt_id', $this->getPrompt()->id)
            ->findOrFail($resultId);

        $this->selectedResultId = $resultId;
        $this->applyResultToView($result);
        $this->applyChainStateFromResult($result);
        $this->syncTestResultSeoMediaContext();
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
        $this->tokenUsageLabel = null;
        $this->lastRawModelUsed = null;
        $this->resetChainProgress();
    }

    protected function resetChainProgress(): void
    {
        $this->chainLastOutput = null;
        $this->chainParentCompleted = false;
        $this->chainSubTasksCompleted = 0;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->getVariableFormSchema())
            ->statePath('variableValues');
    }

    protected function getForms(): array
    {
        return [
            'form',
        ];
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
            Actions\Action::make('refresh_preview')
                ->label('Làm mới xem trước')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->refreshCompiledPreview()),
        ];
    }

    protected function ensureAiModelsReadyOrRedirect(): bool
    {
        $readiness = app(AiModelsReadinessService::class);

        if ($readiness->isPromptReady($this->getPrompt())) {
            return true;
        }

        Notification::make()
            ->title('Cần đồng bộ model AI')
            ->body($readiness->blockMessage())
            ->warning()
            ->send();

        $this->redirect($readiness->overviewUrl(), navigate: true);

        return false;
    }

    protected function redirectIfAiModelsNotReady(\Throwable $exception): bool
    {
        if (! $exception instanceof AiModelsNotReadyException) {
            return false;
        }

        Notification::make()
            ->title('Cần đồng bộ model AI')
            ->body($exception->getMessage())
            ->warning()
            ->send();

        $this->redirect($exception->overviewUrl(), navigate: true);

        return true;
    }

    public function runTest(PromptRunnerService $runner): void
    {
        if (! $this->ensureAiModelsReadyOrRedirect()) {
            return;
        }

        $this->isRunning = true;
        $this->errorMessage = null;
        $this->outputText = null;
        $this->compiledPreview = null;
        $this->resetChainProgress();

        $normalized = $this->normalizedVariableValues();

        if ($this->promptUsesLoaiSanPham) {
            $validation = app(PromptLoaiSanPhamOptionsService::class)->validateTestInputs(
                (int) ($normalized[PromptLoaiSanPhamVariable::SITE_FIELD] ?? 0),
                (int) ($normalized[PromptLoaiSanPhamVariable::CATEGORY_FIELD] ?? 0),
                trim((string) ($normalized[PromptLoaiSanPhamVariable::CUSTOM_FIELD] ?? '')),
            );

            if (! ($validation['valid'] ?? false)) {
                $this->isRunning = false;

                Notification::make()
                    ->title('Thiếu thông tin loại sản phẩm')
                    ->body((string) ($validation['message'] ?? ''))
                    ->warning()
                    ->send();

                return;
            }
        }

        if ($this->promptUsesInput && trim((string) ($normalized['input'] ?? '')) === '') {
            $this->isRunning = false;

            Notification::make()
                ->title('Thiếu biến input')
                ->body('Prompt dùng {{input}} — nhập kết quả mô phỏng từ edge nối vào trước khi chạy thử.')
                ->warning()
                ->send();

            return;
        }

        try {
            $runFullChain = ! $this->hasDependentSubTasks;
            $persistContext = $this->resolvePromptMediaPersistContext($normalized);
            $result = PromptMediaPersistContext::using(
                $persistContext['site_id'],
                $persistContext['article_id'],
                $persistContext['prompt_id'],
                fn () => $runner->run($this->getPrompt(), $normalized, null, false, $runFullChain),
            );

            if ($this->usesStepByStepChain()) {
                $this->chainParentCompleted = true;
                $this->chainLastOutput = (string) ($result->output_text ?? '');
                $this->chainSubTasksCompleted = 0;
            }

            Notification::make()
                ->title($this->usesStepByStepChain() && $this->hasMoreSubTasksToRun()
                    ? 'Đã chạy xong prompt cha'
                    : 'Chạy thử thành công')
                ->body($this->usesStepByStepChain() && $this->hasMoreSubTasksToRun()
                    ? 'Bấm nút bên dưới để chạy từng prompt con lần lượt.'
                    : null)
                ->success()
                ->send();

            unset($this->promptResults);
            $this->selectResult((int) $result->id);
        } catch (PromptRunException $exception) {
            if ($this->redirectIfAiModelsNotReady($exception)) {
                return;
            }

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

    public function runNextSubTask(PromptRunnerService $runner): void
    {
        if (! $this->ensureAiModelsReadyOrRedirect()) {
            return;
        }

        if (! $this->usesStepByStepChain()) {
            Notification::make()
                ->title('Prompt này không cần chạy prompt con')
                ->body('Prompt ảnh đã render trực tiếp trong 1 lần chạy.')
                ->warning()
                ->send();

            return;
        }

        if (! $this->chainParentCompleted || ! $this->hasMoreSubTasksToRun()) {
            Notification::make()
                ->title('Không thể chạy bước tiếp theo')
                ->body('Chạy prompt cha trước, hoặc đã hết prompt con.')
                ->warning()
                ->send();

            return;
        }

        if (blank($this->chainLastOutput)) {
            Notification::make()
                ->title('Thiếu kết quả bước trước')
                ->body('Chạy lại prompt cha.')
                ->warning()
                ->send();

            return;
        }

        $this->isRunning = true;
        $this->errorMessage = null;

        $normalized = $this->normalizedVariableValues();
        $normalized['PARENT_RESULT'] = (string) $this->chainLastOutput;
        $subTaskIndex = $this->chainSubTasksCompleted;

        try {
            $persistContext = $this->resolvePromptMediaPersistContext($normalized);
            $result = PromptMediaPersistContext::using(
                $persistContext['site_id'],
                $persistContext['article_id'],
                $persistContext['prompt_id'],
                fn () => $runner->run(
                    $this->getPrompt(),
                    $normalized,
                    null,
                    false,
                    true,
                    $subTaskIndex,
                ),
            );

            $this->chainLastOutput = (string) ($result->output_text ?? '');
            $this->chainSubTasksCompleted++;

            $hasMore = $this->hasMoreSubTasksToRun();

            Notification::make()
                ->title('Đã chạy xong ' . ($this->dependentSubTaskSteps[$subTaskIndex]['name'] ?? 'prompt con'))
                ->body($hasMore ? 'Bấm nút bên dưới để chạy prompt con tiếp theo.' : 'Đã hoàn tất toàn bộ chuỗi prompt.')
                ->success()
                ->send();

            unset($this->promptResults);
            $this->selectResult((int) $result->id);
        } catch (PromptRunException $exception) {
            if ($this->redirectIfAiModelsNotReady($exception)) {
                return;
            }

            $this->errorMessage = $exception->getMessage();

            Notification::make()
                ->title('Chạy prompt con thất bại')
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

    public function refreshCompiledPreview(): void
    {
        $this->getRecord()->refresh();

        $values = $this->form->getState();
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        try {
            $this->compiledPreview = app(PromptRunnerService::class)->compilePrompt(
                $this->getPrompt(),
                $normalized,
            );
        } catch (\Throwable) {
            $this->compiledPreview = null;
        }
    }

    protected function applyChainStateFromResult(PromptResult $result): void
    {
        if (! $this->usesStepByStepChain()) {
            $this->resetChainProgress();

            return;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        if (! ($snapshot['chain_mode'] ?? false)) {
            $this->resetChainProgress();

            return;
        }

        if ($result->status !== 'completed') {
            return;
        }

        $this->chainParentCompleted = true;
        $this->chainLastOutput = (string) ($result->output_text ?? '');

        $step = (string) ($snapshot['chain_step'] ?? '');
        if ($step === 'task') {
            $this->chainSubTasksCompleted = 0;

            return;
        }

        if ($step === 'sub_task') {
            $this->chainSubTasksCompleted = max(1, (int) ($snapshot['chain_step_index'] ?? 1));
        }
    }

    protected function applyResultToView(PromptResult $result): void
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        $savedVariables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
        foreach ($savedVariables as $key => $value) {
            $name = (string) $key;
            $this->variableValues[$name] = is_string($value) ? $value : (string) $value;
        }

        if ($this->promptUsesLoaiSanPham) {
            $this->stripLoaiSanPhamComputedVariableKeys();
        }

        $this->syncVariableValueKeys();
        $this->form->fill($this->variableValues);

        $usage = is_array($result->token_usage) ? $result->token_usage : null;
        $this->tokenUsageLabel = PromptTokenUsage::formatLabel($usage);
        $this->lastRawModelUsed = filled($snapshot['raw_model_used'] ?? null)
            ? (string) $snapshot['raw_model_used']
            : null;

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

        // Luôn ghép lại từ prompt/parts mới nhất — không dùng snapshot compiled_prompt (có thể là bản cũ).
        $this->refreshCompiledPreview();
    }

    public function tokenUsageLabelFor(PromptResult $result): ?string
    {
        $usage = is_array($result->token_usage) ? $result->token_usage : null;

        return PromptTokenUsage::formatLabel($usage);
    }

    public function modelUsedLabelFor(PromptResult $result): ?string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $raw = trim((string) ($snapshot['raw_model_used'] ?? $snapshot['model'] ?? ''));

        return $raw !== '' ? $raw : null;
    }

    public function resultToolBadgeFor(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $tool = strtolower(trim((string) ($snapshot['tools'] ?? 'default')));

        return match ($tool) {
            'image' => 'IMAGE',
            'video' => 'VIDEO',
            default => 'TEXT',
        };
    }

    public function aiResultSectionHeading(): string
    {
        $parts = array_values(array_filter([
            $this->tokenUsageLabel,
            filled($this->lastRawModelUsed) ? 'Model: ' . $this->lastRawModelUsed : null,
        ]));

        return $parts !== [] ? 'Kết quả AI (' . implode(' · ', $parts) . ')' : 'Kết quả AI';
    }

    public function shouldShowCompiledPreview(): bool
    {
        return ! $this->hasDependentSubTasks;
    }

    public function currentMediaOutputUrl(): ?string
    {
        $raw = trim((string) ($this->outputText ?? ''));
        if ($raw === '') {
            return null;
        }

        $firstLine = trim(explode("\n", $raw, 2)[0] ?? '');
        $isUrl = str_starts_with($firstLine, '/storage/') || (bool) preg_match('#^https?://#i', $firstLine);

        return $isUrl ? $firstLine : null;
    }

    public function isImageToolPrompt(): bool
    {
        return trim((string) ($this->getPrompt()->tools ?? 'default')) === 'image';
    }

    public function isVideoToolPrompt(): bool
    {
        return trim((string) ($this->getPrompt()->tools ?? 'default')) === 'video';
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{site_id: ?int, article_id: ?int, prompt_id: ?int}
     */
    protected function resolvePromptMediaPersistContext(array $variables): array
    {
        $articleId = null;

        if ($this->publishArticleId !== null && $this->publishArticleId > 0) {
            $articleId = $this->publishArticleId;
        } elseif (filled($variables['article_id'] ?? null)) {
            $articleId = (int) $variables['article_id'];
        }

        $siteId = null;
        if ($articleId !== null && $articleId > 0) {
            $siteId = (int) (SeoArticle::query()->whereKey($articleId)->value('site_id') ?? 0);
        }

        if (($siteId === null || $siteId <= 0) && $this->promptUsesLoaiSanPham) {
            $siteId = (int) ($variables[PromptLoaiSanPhamVariable::SITE_FIELD] ?? 0);
        }

        return [
            'site_id' => $siteId > 0 ? $siteId : null,
            'article_id' => $articleId > 0 ? $articleId : null,
            'prompt_id' => (int) $this->getPrompt()->id,
        ];
    }

    protected function syncTestResultSeoMediaContext(): void
    {
        if (! $this->isImageToolPrompt() && ! $this->isVideoToolPrompt()) {
            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media === null) {
            return;
        }

        $context = $this->resolvePromptMediaPersistContext($this->normalizedVariableValues());
        PromptMediaPersistContext::using(
            $context['site_id'],
            $context['article_id'],
            $context['prompt_id'],
            static function () use ($media): void {
                $updates = PromptMediaPersistContext::fillMissingOnMedia($media);
                if ($updates !== []) {
                    $media->update($updates);
                }
            },
        );
    }

    public function testResultSiteId(): ?int
    {
        $media = $this->testResultSeoMedia();
        if ($media !== null && (int) ($media->site_id ?? 0) > 0) {
            return (int) $media->site_id;
        }

        if ($this->promptUsesLoaiSanPham) {
            $siteId = (int) ($this->normalizedVariableValues()[PromptLoaiSanPhamVariable::SITE_FIELD] ?? 0);
            if ($siteId > 0) {
                return $siteId;
            }
        }

        if ($this->publishArticleId !== null && $this->publishArticleId > 0) {
            $siteId = (int) (SeoArticle::query()->whereKey($this->publishArticleId)->value('site_id') ?? 0);
            if ($siteId > 0) {
                return $siteId;
            }
        }

        return null;
    }

    public function testResultSeoMedia(): ?SeoMedia
    {
        $url = $this->currentMediaOutputUrl();
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
            return null;
        }

        $relative = ltrim(substr($path, strlen('/storage/')), '/');
        if ($relative === '') {
            return null;
        }

        return SeoMedia::query()
            ->where('path', $relative)
            ->orWhere('url', $url)
            ->orWhere('url', 'like', '%' . $relative)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function testResultImageRow(): array
    {
        $url = (string) ($this->currentMediaOutputUrl() ?? '');
        $media = $this->testResultSeoMedia();
        $siteId = $this->testResultSiteId();
        $kind = 'local';

        if ($media !== null) {
            $source = (string) $media->source;
            if (str_starts_with($source, 'ai_') && (int) ($media->site_id ?? 0) <= 0 && ($siteId === null || $siteId <= 0)) {
                $kind = 'generated';
            }
        }

        return [
            'url' => $url,
            'seo_media_id' => $media !== null ? (int) $media->id : 0,
            'wp_attachment_id' => $media !== null ? (int) ($media->wp_attachment_id ?? 0) : 0,
            'slug' => $media !== null ? (string) ($media->slug ?? '') : '',
            'kind' => $kind,
            'article_id' => $this->publishArticleId,
        ];
    }

    public function testResultImageSplitterUrl(): ?string
    {
        $media = $this->testResultSeoMedia();
        if ($media === null) {
            return null;
        }

        return MediaImageEditor::urlForMedia((int) $media->id, 'splitter');
    }

    public function testResultCanOpenImageEditor(): bool
    {
        return $this->testResultSeoMedia() !== null
            && ! $this->testResultNeedsSiteForMediaActions();
    }

    public function openResultImageEditor(): void
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            Notification::make()
                ->title('Chưa chọn tên miền')
                ->body('Chọn tên miền hoặc bài viết đích trước khi chỉnh sửa ảnh.')
                ->warning()
                ->send();

            return;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Không tìm thấy domain')->danger()->send();

            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media === null) {
            Notification::make()
                ->title('Không tìm thấy file ảnh')
                ->body('Ảnh chưa được lưu trên server — chạy lại prompt.')
                ->warning()
                ->send();

            return;
        }

        if ((int) ($media->site_id ?? 0) <= 0) {
            $media->update(['site_id' => $siteId]);
            $media->refresh();
        }

        $imageRow = $this->testResultImageRow();
        $imageRow['seo_media_id'] = (int) $media->id;
        $imageRow['kind'] = 'local';

        try {
            $resolved = app(SeoMediaImageEditorResolverService::class)
                ->resolve($site, $imageRow);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Không mở được trình chỉnh sửa')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->js('window.open(' . json_encode($resolved['editor_url']) . ', "_blank")');
    }

    public function testResultMediaLibraryUrl(): ?string
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        return MediaLibrary::getUrl(['siteId' => $siteId]);
    }

    public function testResultNeedsSiteForMediaActions(): bool
    {
        return $this->testResultSiteId() === null || $this->testResultSiteId() <= 0;
    }

    public function testResultIsGeneratedMedia(): bool
    {
        return ($this->testResultImageRow()['kind'] ?? '') === 'generated';
    }

    public function assignResultToSiteLibrary(): void
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            Notification::make()
                ->title('Chưa chọn tên miền')
                ->body('Chọn tên miền ở biến loại sản phẩm hoặc chọn bài viết đích (đã đồng bộ WP) trước khi gán ảnh vào thư viện.')
                ->warning()
                ->send();

            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media === null) {
            Notification::make()
                ->title('Không tìm thấy file ảnh')
                ->body('Ảnh AI chưa được lưu trên server — chạy lại prompt hoặc kiểm tra đường dẫn /storage/.')
                ->warning()
                ->send();

            return;
        }

        if ((int) ($media->site_id ?? 0) === $siteId) {
            Notification::make()
                ->title('Ảnh đã thuộc thư viện domain này')
                ->success()
                ->send();

            return;
        }

        $media->update(['site_id' => $siteId]);

        Notification::make()
            ->title('Đã gán ảnh vào thư viện')
            ->body('Có thể áp dụng đóng dấu hoặc mở thư viện hình ảnh.')
            ->success()
            ->send();
    }

    public function applyResultWatermark(): void
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            Notification::make()
                ->title('Chưa chọn tên miền')
                ->body('Chọn tên miền hoặc bài viết đích trước khi đóng dấu. Với ảnh Gen AI, bấm «Gán vào thư viện» trước.')
                ->warning()
                ->send();

            return;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Không tìm thấy domain')->danger()->send();

            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media !== null && (int) ($media->site_id ?? 0) <= 0) {
            $media->update(['site_id' => $siteId]);
            $media->refresh();
        }

        $imageRow = $this->testResultImageRow();
        if (($imageRow['kind'] ?? '') === 'generated') {
            Notification::make()
                ->title('Ảnh Gen AI chưa gán domain')
                ->body('Bấm «Gán vào thư viện» rồi thử đóng dấu lại.')
                ->warning()
                ->send();

            return;
        }

        $result = app(SeoMediaLibraryImageActionService::class)->applyWatermark($site, $imageRow);

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title((string) ($result['message'] ?? 'Không áp dụng được đóng dấu.'))
                ->warning()
                ->send();

            return;
        }

        $newUrl = (string) ($result['url'] ?? $imageRow['url']);
        if ($newUrl !== '') {
            $this->outputText = $newUrl;
        }

        Notification::make()
            ->title((string) ($result['message'] ?? 'Đã áp dụng đóng dấu.'))
            ->success()
            ->send();
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

    protected function syncVariableValueKeys(): void
    {
        foreach ($this->variableDefinitions as $definition) {
            $name = (string) $definition['name'];
            if (! array_key_exists($name, $this->variableValues)) {
                $this->variableValues[$name] = '';
            }
        }

        if ($this->promptUsesInput && ! array_key_exists('input', $this->variableValues)) {
            $this->variableValues['input'] = '';
        }

        if ($this->promptUsesLoaiSanPham) {
            $this->stripLoaiSanPhamComputedVariableKeys();

            foreach ([
                PromptLoaiSanPhamVariable::SITE_FIELD => '',
                PromptLoaiSanPhamVariable::CATEGORY_FIELD => '',
                PromptLoaiSanPhamVariable::CUSTOM_FIELD => '',
            ] as $key => $default) {
                if (! array_key_exists($key, $this->variableValues)) {
                    $this->variableValues[$key] = $default;
                }
            }
        }
    }

    /**
     * Bỏ khóa chỉ dùng khi compile — không bind Filament/Alpine (tránh entangle lỗi).
     */
    protected function stripLoaiSanPhamComputedVariableKeys(): void
    {
        foreach ([
            PromptLoaiSanPhamVariable::NAME,
            'LOAI_SAN_PHAM',
            'loai_san_pham_preview',
        ] as $key) {
            unset($this->variableValues[$key]);
        }
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected function getVariableFormSchema(): array
    {
        if (! $this->record) {
            return [];
        }

        if ($this->promptUsesInput) {
            $this->syncVariableValueKeys();
        }

        $definitions = $this->variableDefinitions;

        if ($definitions === [] && ! $this->promptUsesInput && ! $this->promptUsesLoaiSanPham) {
            return [
                Forms\Components\Placeholder::make('no_variables')
                    ->label('')
                    ->content('Prompt này không khai báo biến @{{tên}}. Bạn có thể chạy thử trực tiếp.'),
            ];
        }

        if ($definitions === [] && $this->promptUsesInput) {
            return [
                $this->makeInputSupplementField(),
            ];
        }

        $inputDefinition = collect($definitions)->firstWhere('name', 'input');
        $otherDefinitions = collect($definitions)->where('name', '!=', 'input')->values();

        $schema = [];

        if ($inputDefinition !== null) {
            $schema[] = $this->makeInputSupplementField($inputDefinition);
        }

        if ($this->promptUsesLoaiSanPham) {
            $schema[] = $this->makeLoaiSanPhamVariableGroup();
        }

        foreach ($otherDefinitions as $definition) {
            if (PromptLoaiSanPhamVariable::isLoaiSanPhamName((string) $definition['name'])) {
                continue;
            }

            $schema[] = $this->makeVariableField($definition);
        }

        return $schema;
    }

    protected function makeLoaiSanPhamVariableGroup(): Forms\Components\Section
    {
        $options = app(PromptLoaiSanPhamOptionsService::class);

        return Forms\Components\Section::make('Loại sản phẩm (product_cat)')
            ->description('Chỉ áp dụng khi post_type = product. Chọn tên miền, rồi chọn product_cat hoặc chỉ điền Custom (một trong hai là đủ).')
            ->schema([
                Forms\Components\Select::make(PromptLoaiSanPhamVariable::SITE_FIELD)
                    ->label('Tên miền')
                    ->options(fn (): array => $options->siteOptionsForUser())
                    ->searchable()
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(function (Forms\Set $set): void {
                        $set(PromptLoaiSanPhamVariable::CATEGORY_FIELD, null);
                    }),
                Forms\Components\Select::make(PromptLoaiSanPhamVariable::CATEGORY_FIELD)
                    ->label('Danh mục sản phẩm (product_cat)')
                    ->options(fn (Get $get): array => $options->productCategoryOptionsForSite(
                        (int) $get(PromptLoaiSanPhamVariable::SITE_FIELD),
                    ))
                    ->searchable()
                    ->required(fn (Get $get): bool => trim((string) $get(PromptLoaiSanPhamVariable::CUSTOM_FIELD)) === '')
                    ->native(false)
                    ->helperText('Tùy chọn nếu đã điền Custom. Lấy từ danh mục đồng bộ (product_category); đồng bộ domain trước nếu danh sách trống.')
                    ->hidden(fn (Get $get): bool => blank($get(PromptLoaiSanPhamVariable::SITE_FIELD)))
                    ->live(),
                Forms\Components\TextInput::make(PromptLoaiSanPhamVariable::CUSTOM_FIELD)
                    ->label('Custom')
                    ->placeholder('VD: túi vải, balo laptop, Cặp học sinh…')
                    ->helperText('Có thể dùng thay cho product_cat khi chạy thử.')
                    ->maxLength(500)
                    ->live(debounce: 400),
                Forms\Components\Placeholder::make('loai_san_pham_preview')
                    ->dehydrated(false)
                    ->label('Giá trị gửi vào {{loai_san_pham}} / {{LOAI_SAN_PHAM}}')
                    ->content(function (Get $get) use ($options): HtmlString {
                        $text = $options->buildCompositeValue(
                            (int) $get(PromptLoaiSanPhamVariable::SITE_FIELD),
                            (int) $get(PromptLoaiSanPhamVariable::CATEGORY_FIELD),
                            trim((string) $get(PromptLoaiSanPhamVariable::CUSTOM_FIELD)),
                        );

                        return new HtmlString(
                            $text !== ''
                                ? '<span class="text-sm font-medium text-gray-950 dark:text-white">' . e($text) . '</span>'
                                : '<span class="text-sm text-gray-500">—</span>',
                        );
                    })
                    ->helperText('Tự động ghép từ danh mục + custom khi chạy thử.'),
            ])
            ->columnSpanFull();
    }

    /**
     * @param  array{name: string, label: string, description: ?string}|null  $definition
     */
    protected function makeInputSupplementField(?array $definition = null): Forms\Components\Textarea
    {
        $helper = filled($definition['description'] ?? null)
            ? (string) $definition['description']
            : 'Trên Workflow Builder, {{input}} nhận kết quả từ bước trước. Khi chạy thử tại đây, dán hoặc nhập nội dung mô phỏng.';

        return Forms\Components\Textarea::make('input')
            ->label((string) ($definition['label'] ?? 'Kết quả edge nối vào (SEO Flow)'))
            ->rows(6)
            ->columnSpanFull()
            ->helperText($helper);
    }

    /**
     * @param  array{name: string, label: string, description: ?string}  $definition
     */
    protected function makeVariableField(array $definition): Forms\Components\Textarea
    {
        $field = Forms\Components\Textarea::make((string) $definition['name'])
            ->label((string) $definition['label'])
            ->rows(2)
            ->columnSpanFull();

        if (filled($definition['description'] ?? null)) {
            $field->helperText((string) $definition['description']);
        }

        return $field;
    }

    protected function getPrompt(): SeoPrompt
    {
        /** @var SeoPrompt $record */
        $record = $this->getRecord();

        return $record;
    }
}
