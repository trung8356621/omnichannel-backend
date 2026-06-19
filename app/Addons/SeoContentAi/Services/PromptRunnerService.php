<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoPromptPart;
use App\Addons\SeoContentAi\Support\GeminiModelCatalog;
use App\Addons\SeoContentAi\Support\Utf8Sanitizer;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;

final class PromptRunnerService
{
    public function __construct(
        private readonly AiExecutionService $aiExecution,
        private readonly MediaGenerationService $mediaGeneration,
        private readonly PromptMediaStorageService $promptMediaStorage,
        private readonly AiModelRouterService $aiModelRouter,
        private readonly AiModelsReadinessService $aiModelsReadiness,
    ) {}

    private const ROLE_HEADINGS = [
        'role' => 'Vai trò',
        'context' => 'Bối cảnh',
        'task' => 'Nhiệm vụ',
        'sub_task' => 'Nhiệm vụ phụ thuộc',
        'formatting' => 'Định dạng đầu ra',
        'constraints' => 'Ràng buộc',
    ];

    /**
     * @param  array<string, string>  $variables
     */
    /**
     * @param  bool  $runFullDependentChain  false = chỉ chạy task cha (dùng khi test từng bước)
     * @param  int|null  $onlySubTaskIndex  Chỉ chạy một sub_task (0-based); cần PARENT_RESULT trong $variables
     */
    public function run(
        SeoPrompt $prompt,
        array $variables,
        ?string $modelOverride = null,
        bool $isTaskMode = true,
        bool $runFullDependentChain = true,
        ?int $onlySubTaskIndex = null,
    ): PromptResult {
        $prompt->loadMissing(['aiConnection']);

        $variables = Utf8Sanitizer::variablesForAi($variables);
        $variables = app(PromptLanguageVariableService::class)->mergeInto($variables);

        $connection = $prompt->aiConnection;
        if ($connection === null) {
            throw new PromptRunException('Prompt chưa được gắn kết nối AI.');
        }

        if ($connection->status !== 'active') {
            throw new PromptRunException('Kết nối AI đang tắt hoặc không khả dụng.');
        }

        if (blank($connection->api_key)) {
            throw new PromptRunException('Kết nối AI chưa có API Key.');
        }

        $this->aiModelsReadiness->assertConnectionReady($connection);

        $toolType = $this->normalizeToolType($prompt);

        if ($toolType === 'image' && $connection->provider !== 'gemini') {
            throw new PromptRunException(
                'Prompt công cụ Hình ảnh yêu cầu kết nối Gemini (Imagen / Nano Banana), không dùng Claude.',
            );
        }

        $category = filled($modelOverride) && \App\Addons\SeoContentAi\Support\AiModelCategory::isValid($modelOverride)
            ? $modelOverride
            : $this->aiModelRouter->resolveCategoryForPrompt($prompt, $toolType);

        // Test chuỗi prompt ảnh: cho phép chạy trực tiếp pipeline ảnh ngay từ nút "Chạy prompt cha".
        // Mục tiêu: ép kết quả bước 1 phải là URL ảnh để người dùng xác nhận render trước khi chạy sub_task.
        if (
            $toolType === 'image'
            && $onlySubTaskIndex === null
            && ! $runFullDependentChain
            && $this->hasDependentSubTasks($prompt)
        ) {
            return $this->runDirectImagePreview(
                $prompt,
                $variables,
                $connection,
                $category,
                $isTaskMode,
            );
        }

        if ($this->hasDependentSubTasks($prompt)) {
            if ($onlySubTaskIndex !== null) {
                return $this->runDependentSubTaskStepOnly(
                    $prompt,
                    $variables,
                    $connection,
                    $category,
                    $isTaskMode,
                    $onlySubTaskIndex,
                );
            }

            if (! $runFullDependentChain) {
                return $this->runDependentParentStepOnly(
                    $prompt,
                    $variables,
                    $connection,
                    $category,
                    $isTaskMode,
                );
            }

            return $this->runDependentSubTaskChain(
                $prompt,
                $variables,
                $connection,
                $category,
                $isTaskMode,
            );
        }

        $compiled = $this->compilePrompt($prompt, $variables);

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'entity_id' => null,
            'user_id' => (int) auth()->id(),
            'site_id' => 0,
            'status' => 'running',
            'input_snapshot' => $this->sanitizeInputSnapshot([
                'variables' => $variables,
                'compiled_prompt' => $compiled,
                'model_category' => $category,
                'is_task_mode' => $isTaskMode,
                'tools' => $toolType,
            ]),
            'started_at' => now(),
        ]);

        try {
            [$output, $usage, $rawModel] = $this->executeWithModelRouting(
                $connection,
                $prompt,
                $compiled,
                $variables,
                $isTaskMode,
                $toolType,
                $category,
            );
            $output = $this->promptMediaStorage->persistRemoteMediaIfPresent($output, $toolType, $rawModel);
            $output = $this->enforceMediaOnlyOutput($output, $toolType);

            $result->update([
                'status' => 'completed',
                'output_text' => $output,
                'token_usage' => is_array($usage) ? $this->sanitizeTokenUsage($usage) : $usage,
                'finished_at' => now(),
                'input_snapshot' => $this->sanitizeInputSnapshot(array_merge(
                    is_array($result->input_snapshot) ? $result->input_snapshot : [],
                    ['raw_model_used' => $rawModel],
                )),
            ]);
        } catch (\Throwable $exception) {
            $result->update([
                'status' => 'failed',
                'error_message' => $this->sanitizeErrorMessage($exception->getMessage()),
                'finished_at' => now(),
            ]);

            throw $exception instanceof PromptRunException
                ? $exception
                : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $result->fresh();
    }

    /**
     * Prompt tools=image + có sub_task + mode test từng bước:
     * bước đầu chạy trực tiếp Imagen/Nano Banana và bắt buộc trả URL ảnh.
     *
     * @param  array<string, string>  $variables
     */
    private function runDirectImagePreview(
        SeoPrompt $prompt,
        array $variables,
        ApiConnection $connection,
        string $category,
        bool $isTaskMode,
    ): PromptResult {
        $compiled = $this->compilePrompt($prompt, $variables);

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'entity_id' => null,
            'user_id' => (int) auth()->id(),
            'site_id' => 0,
            'status' => 'running',
            'input_snapshot' => $this->sanitizeInputSnapshot([
                'variables' => $variables,
                'compiled_prompt' => $compiled,
                'model_category' => $category,
                'is_task_mode' => $isTaskMode,
                'tools' => 'image',
                'chain_mode' => true,
                'chain_step' => 'task',
                'chain_step_index' => 0,
                'direct_image_preview' => true,
            ]),
            'started_at' => now(),
        ]);

        try {
            [$output, $usage] = $this->mediaGeneration->executeImage(
                $connection,
                $prompt,
                $compiled,
                $variables,
                null,
            );

            $output = $this->promptMediaStorage->persistRemoteMediaIfPresent($output, 'image');
            $output = $this->enforceMediaOnlyOutput($output, 'image');

            $result->update([
                'status' => 'completed',
                'output_text' => $output,
                'token_usage' => is_array($usage) ? $this->sanitizeTokenUsage($usage) : $usage,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $result->update([
                'status' => 'failed',
                'error_message' => $this->sanitizeErrorMessage($exception->getMessage()),
                'finished_at' => now(),
            ]);

            throw $exception instanceof PromptRunException
                ? $exception
                : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $result->fresh();
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null, 2: string}
     */
    private function executeWithModelRouting(
        ApiConnection $connection,
        SeoPrompt $prompt,
        string $compiled,
        array $variables,
        bool $isTaskMode,
        string $toolType,
        string $category,
    ): array {
        [$output, $usage, $rawModel] = $this->aiModelRouter->executeWithFailover(
            $connection,
            $category,
            fn (string $rawModelName, ?int $modelId): array => $this->callProvider(
                $connection,
                $prompt,
                $compiled,
                $rawModelName,
                $variables,
                $isTaskMode,
                $toolType,
            ),
        );

        return [$output, $usage, $rawModel];
    }

    public function hasDependentSubTasks(SeoPrompt $prompt): bool
    {
        return $this->getDependentSubTaskParts($prompt)->isNotEmpty();
    }

    /**
     * @return \Illuminate\Support\Collection<int, SeoPromptPart>
     */
    public function getDependentSubTaskParts(SeoPrompt $prompt): \Illuminate\Support\Collection
    {
        return $this->promptParts($prompt)
            ->where('role', 'sub_task')
            ->filter(static fn (SeoPromptPart $part): bool => trim((string) $part->content) !== '')
            ->values();
    }

    /**
     * Chỉ chạy khối task cha (test từng bước).
     *
     * @param  array<string, string>  $variables
     */
    private function runDependentParentStepOnly(
        SeoPrompt $prompt,
        array $variables,
        ApiConnection $connection,
        string $baseCategory,
        bool $isTaskMode,
    ): PromptResult {
        $parts = $this->promptParts($prompt);
        $mainTask = $parts->firstWhere('role', 'task');

        if ($mainTask === null || trim((string) $mainTask->content) === '') {
            throw new PromptRunException("Prompt thiếu khối 'Nhiệm vụ chính' (task).");
        }

        $toolType = $this->normalizeToolType($prompt);

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'entity_id' => null,
            'user_id' => (int) auth()->id(),
            'site_id' => 0,
            'status' => 'running',
            'input_snapshot' => $this->sanitizeInputSnapshot([
                'variables' => $variables,
                'compiled_prompt' => $this->compileChainStep($prompt, $mainTask, $variables),
                'model_category' => $baseCategory,
                'is_task_mode' => $isTaskMode,
                'tools' => $toolType,
                'chain_mode' => true,
                'chain_step' => 'task',
                'chain_step_index' => 0,
            ]),
            'started_at' => now(),
        ]);

        try {
            [$output, $usage, $rawModel] = $this->executeChainStepWithRouting(
                $connection,
                $prompt,
                $mainTask,
                $variables,
                $baseCategory,
                $isTaskMode,
                $toolType,
            );

            $result->update([
                'status' => 'completed',
                'output_text' => $output,
                'token_usage' => is_array($usage) ? $this->sanitizeTokenUsage($usage) : $usage,
                'finished_at' => now(),
                'input_snapshot' => $this->sanitizeInputSnapshot(array_merge(
                    is_array($result->input_snapshot) ? $result->input_snapshot : [],
                    ['raw_model_used' => $rawModel],
                )),
            ]);
        } catch (\Throwable $exception) {
            $result->update([
                'status' => 'failed',
                'error_message' => $this->sanitizeErrorMessage($exception->getMessage()),
                'finished_at' => now(),
            ]);

            throw $exception instanceof PromptRunException
                ? $exception
                : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $result->fresh();
    }

    /**
     * Chỉ chạy một sub_task trong chuỗi (test từng bước; PARENT_RESULT = kết quả bước trước).
     *
     * @param  array<string, string>  $variables
     */
    private function runDependentSubTaskStepOnly(
        SeoPrompt $prompt,
        array $variables,
        ApiConnection $connection,
        string $baseCategory,
        bool $isTaskMode,
        int $subTaskIndex,
    ): PromptResult {
        $subTasks = $this->getDependentSubTaskParts($prompt);

        if ($subTaskIndex < 0 || $subTaskIndex >= $subTasks->count()) {
            throw new PromptRunException('Không tìm thấy prompt con #'.($subTaskIndex + 1).' trong chuỗi.');
        }

        if (trim((string) ($variables['PARENT_RESULT'] ?? '')) === '') {
            throw new PromptRunException('Thiếu {{PARENT_RESULT}} — chạy prompt cha (hoặc bước con trước) trước.');
        }

        /** @var SeoPromptPart $subTask */
        $subTask = $subTasks->get($subTaskIndex);
        $toolType = $this->normalizeToolType($prompt);
        $stepName = filled($subTask->name) ? (string) $subTask->name : ('Prompt con '.($subTaskIndex + 1));

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'entity_id' => null,
            'user_id' => (int) auth()->id(),
            'site_id' => 0,
            'status' => 'running',
            'input_snapshot' => $this->sanitizeInputSnapshot([
                'variables' => $variables,
                'compiled_prompt' => $this->compileChainStep($prompt, $subTask, $variables),
                'model_category' => $baseCategory,
                'is_task_mode' => $isTaskMode,
                'tools' => $toolType,
                'chain_mode' => true,
                'chain_step' => 'sub_task',
                'chain_step_index' => $subTaskIndex + 1,
                'chain_step_name' => $stepName,
            ]),
            'started_at' => now(),
        ]);

        try {
            [$output, $usage, $rawModel] = $this->executeChainStepWithRouting(
                $connection,
                $prompt,
                $subTask,
                $variables,
                $baseCategory,
                $isTaskMode,
                $toolType,
            );

            $result->update([
                'status' => 'completed',
                'output_text' => $output,
                'token_usage' => is_array($usage) ? $this->sanitizeTokenUsage($usage) : $usage,
                'finished_at' => now(),
                'input_snapshot' => $this->sanitizeInputSnapshot(array_merge(
                    is_array($result->input_snapshot) ? $result->input_snapshot : [],
                    ['raw_model_used' => $rawModel],
                )),
            ]);
        } catch (\Throwable $exception) {
            $result->update([
                'status' => 'failed',
                'error_message' => $this->sanitizeErrorMessage($exception->getMessage()),
                'finished_at' => now(),
            ]);

            throw $exception instanceof PromptRunException
                ? $exception
                : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $result->fresh();
    }

    /**
     * Chuỗi task → sub_task: mỗi bước cập nhật {{PARENT_RESULT}}; image/video lưu thư viện nội bộ.
     *
     * @param  array<string, string>  $variables
     */
    private function runDependentSubTaskChain(
        SeoPrompt $prompt,
        array $variables,
        ApiConnection $connection,
        string $baseCategory,
        bool $isTaskMode,
    ): PromptResult {
        $parts = $this->promptParts($prompt);
        $mainTask = $parts->firstWhere('role', 'task');
        $subTasks = $parts->where('role', 'sub_task')->values();

        if ($mainTask === null || trim((string) $mainTask->content) === '') {
            throw new PromptRunException("Prompt thiếu khối 'Nhiệm vụ chính' (task).");
        }

        $toolType = $this->normalizeToolType($prompt);
        $chainVariables = $variables;

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'entity_id' => null,
            'user_id' => (int) auth()->id(),
            'site_id' => 0,
            'status' => 'running',
            'input_snapshot' => $this->sanitizeInputSnapshot([
                'variables' => $variables,
                'compiled_prompt' => $this->compilePrompt($prompt, $variables),
                'model_category' => $baseCategory,
                'is_task_mode' => $isTaskMode,
                'tools' => $toolType,
                'chain_mode' => true,
            ]),
            'started_at' => now(),
        ]);

        $usageAggregate = null;
        $chainSteps = [];

        try {
            [$parentOutput, $usage] = $this->executeChainStep(
                $connection,
                $prompt,
                $mainTask,
                $chainVariables,
                $baseCategory,
                $isTaskMode,
                $toolType,
            );
            $usageAggregate = $usage;
            $chainVariables['PARENT_RESULT'] = $parentOutput;
            $chainSteps[] = [
                'role' => 'task',
                'name' => filled($mainTask->name) ? (string) $mainTask->name : 'Nhiệm vụ chính',
                'value' => $parentOutput,
            ];

            $finalOutput = $parentOutput;

            foreach ($subTasks as $subTask) {
                if (trim((string) $subTask->content) === '') {
                    continue;
                }

                [$subOutput, $subUsage] = $this->executeChainStep(
                    $connection,
                    $prompt,
                    $subTask,
                    $chainVariables,
                    $baseCategory,
                    $isTaskMode,
                    $toolType,
                );
                $usageAggregate = $this->mergeUsage($usageAggregate, $subUsage);
                $chainVariables['PARENT_RESULT'] = $subOutput;
                $finalOutput = $subOutput;
                $chainSteps[] = [
                    'role' => 'sub_task',
                    'name' => filled($subTask->name) ? (string) $subTask->name : 'Nhiệm vụ phụ thuộc',
                    'value' => $subOutput,
                ];
            }

            $result->update([
                'status' => 'completed',
                'output_text' => $finalOutput,
                'token_usage' => is_array($usageAggregate) ? $this->sanitizeTokenUsage($usageAggregate) : $usageAggregate,
                'finished_at' => now(),
                'input_snapshot' => $this->sanitizeInputSnapshot(array_merge(
                    is_array($result->input_snapshot) ? $result->input_snapshot : [],
                    [
                        'variables' => $chainVariables,
                        'chain_steps' => $chainSteps,
                    ],
                )),
            ]);
        } catch (\Throwable $exception) {
            $result->update([
                'status' => 'failed',
                'error_message' => $this->sanitizeErrorMessage($exception->getMessage()),
                'finished_at' => now(),
            ]);

            throw $exception instanceof PromptRunException
                ? $exception
                : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $result->fresh();
    }

    /**
     * Một bước trong chuỗi task/sub_task (dùng cho ImageGenerationChainService).
     *
     * @param  array<string, string>  $variables
     */
    public function runChainStepOutput(
        SeoPrompt $prompt,
        SeoPromptPart $stepPart,
        array $variables,
        ?string $categoryOverride = null,
        bool $isTaskMode = true,
    ): string {
        $variables = Utf8Sanitizer::variablesForAi($variables);

        $prompt->loadMissing(['aiConnection']);
        $connection = $prompt->aiConnection;
        if ($connection === null) {
            throw new PromptRunException('Prompt chưa được gắn kết nối AI.');
        }

        $this->aiModelsReadiness->assertConnectionReady($connection);

        $toolType = $this->normalizeToolType($prompt);
        $category = filled($categoryOverride) && \App\Addons\SeoContentAi\Support\AiModelCategory::isValid($categoryOverride)
            ? $categoryOverride
            : $this->aiModelRouter->resolveCategoryForPrompt($prompt, $toolType);

        [$output] = $this->executeChainStep(
            $connection,
            $prompt,
            $stepPart,
            $variables,
            $category,
            $isTaskMode,
            $toolType,
        );

        return $output;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function executeChainStep(
        ApiConnection $connection,
        SeoPrompt $prompt,
        SeoPromptPart $stepPart,
        array $variables,
        string $baseCategory,
        bool $isTaskMode,
        string $toolType,
    ): array {
        [$output, $usage] = $this->executeChainStepWithRouting(
            $connection,
            $prompt,
            $stepPart,
            $variables,
            $baseCategory,
            $isTaskMode,
            $toolType,
        );

        return [$output, $usage];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null, 2: string}
     */
    private function executeChainStepWithRouting(
        ApiConnection $connection,
        SeoPrompt $prompt,
        SeoPromptPart $stepPart,
        array $variables,
        string $baseCategory,
        bool $isTaskMode,
        string $toolType,
    ): array {
        $compiled = $this->compileChainStep($prompt, $stepPart, $variables);
        $effectiveTool = $this->resolveStepToolType($prompt, $stepPart, $toolType);
        $stepCategory = $this->resolveStepCategory($prompt, $stepPart, $baseCategory, $effectiveTool);

        [$output, $usage, $rawModel] = $this->executeWithModelRouting(
            $connection,
            $prompt,
            $compiled,
            $variables,
            $isTaskMode,
            $effectiveTool,
            $stepCategory,
        );

        $output = $this->promptMediaStorage->persistRemoteMediaIfPresent($output, $effectiveTool, $rawModel);
        $output = $this->enforceMediaOnlyOutput($output, $effectiveTool);

        return [$output, $usage, $rawModel];
    }

    private function resolveStepCategory(
        SeoPrompt $prompt,
        SeoPromptPart $stepPart,
        string $baseCategory,
        string $effectiveToolType,
    ): string {
        if ($effectiveToolType === 'image') {
            return \App\Addons\SeoContentAi\Support\AiModelCategory::IMAGEN_PRO;
        }

        if ($this->hasDependentSubTasks($prompt) && (string) $stepPart->role === 'task') {
            return \App\Addons\SeoContentAi\Support\AiModelCategory::GEMINI_FLASH;
        }

        return $baseCategory;
    }

    /**
     * Chuỗi task/sub_task: bước task = văn bản; sub_task = sinh ảnh (Imagen / Nano Banana).
     * Công cụ «Hình ảnh» không có sub_task → toàn bộ prompt dùng pipeline ảnh.
     */
    private function resolveStepToolType(SeoPrompt $prompt, SeoPromptPart $stepPart, string $promptToolType): string
    {
        if ($promptToolType === 'video') {
            return 'video';
        }

        if ($this->hasDependentSubTasks($prompt)) {
            return (string) $stepPart->role === 'task' ? 'default' : 'image';
        }

        if ($promptToolType === 'image') {
            return 'image';
        }

        return 'default';
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function compileChainStep(SeoPrompt $prompt, SeoPromptPart $stepPart, array $variables): string
    {
        $systemBlocks = [];
        foreach ($this->promptParts($prompt) as $part) {
            if (in_array((string) $part->role, ['task', 'sub_task'], true)) {
                continue;
            }

            $block = $this->formatPartBlock($part, $variables);
            if ($block !== '') {
                $systemBlocks[] = $block;
            }
        }

        $stepBlock = $this->formatPartBlock($stepPart, $variables);
        if ($stepBlock === '') {
            throw new PromptRunException('Bước chuỗi prompt không có nội dung.');
        }

        if ($systemBlocks === []) {
            return Utf8Sanitizer::string($stepBlock);
        }

        return Utf8Sanitizer::string(implode("\n\n", $systemBlocks)."\n\n---\n\n".$stepBlock);
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     * @return array<string, mixed>|null
     */
    private function mergeUsage(?array $a, ?array $b): ?array
    {
        return $b ?? $a;
    }

    private function normalizeToolType(SeoPrompt $prompt): string
    {
        $tool = trim((string) ($prompt->tools ?? 'default'));

        return in_array($tool, ['default', 'image', 'video'], true) ? $tool : 'default';
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function compilePrompt(SeoPrompt $prompt, array $variables): string
    {
        $variables = app(PromptLanguageVariableService::class)->mergeInto($variables);
        $parts = $this->promptParts($prompt);
        $blocks = [];

        foreach ($parts as $part) {
            $block = $this->formatPartBlock($part, $variables);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        if ($blocks === []) {
            throw new PromptRunException('Prompt không có nội dung thành phần nào.');
        }

        return Utf8Sanitizer::string(implode("\n\n", $blocks));
    }

    /**
     * @return \Illuminate\Support\Collection<int, SeoPromptPart>
     */
    private function promptParts(SeoPrompt $prompt): \Illuminate\Support\Collection
    {
        return $prompt->resolvedParts();
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function formatPartBlock(SeoPromptPart $part, array $variables): string
    {
        if ((string) $part->role === 'global_constraints') {
            return '';
        }

        $content = trim($this->substituteVariables(Utf8Sanitizer::string((string) $part->content), $variables));
        if ($content === '') {
            return '';
        }

        $heading = self::ROLE_HEADINGS[$part->role] ?? ucfirst((string) $part->role);
        if (in_array($part->role, ['task', 'sub_task'], true) && filled($part->name)) {
            $heading .= ': '.Utf8Sanitizer::string((string) $part->name);
        }

        $lines = ["## {$heading}", $content];

        $meta = is_array($part->meta) ? $part->meta : [];
        $rules = trim(Utf8Sanitizer::string((string) ($meta['rules'] ?? '')));
        if ($rules !== '') {
            $lines[] = '';
            $lines[] = 'Quy tắc:';
            $lines[] = $this->substituteVariables($rules, $variables);
        }

        if ($part->role === 'sub_task') {
            $specific = trim(Utf8Sanitizer::string((string) ($meta['specific_constraints'] ?? '')));
            if ($specific !== '') {
                $lines[] = '';
                $lines[] = 'Ràng buộc riêng (sub-prompt):';
                $lines[] = $this->substituteVariables($specific, $variables);
            }
        }

        return Utf8Sanitizer::string(implode("\n", $lines));
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function substituteVariables(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $key = $matches[1];

                return array_key_exists($key, $variables)
                    ? Utf8Sanitizer::string((string) $variables[$key])
                    : $matches[0];
            },
            Utf8Sanitizer::string($text),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function sanitizeInputSnapshot(array $snapshot): array
    {
        return Utf8Sanitizer::arrayDeep($snapshot);
    }

    /**
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>|null
     */
    private function sanitizeTokenUsage(?array $usage): ?array
    {
        return $usage === null ? null : $this->sanitizeInputSnapshot($usage);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        return Utf8Sanitizer::string($message);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    /**
     * @param  array<string, string>  $variables
     */
    private function callProvider(
        ApiConnection $connection,
        SeoPrompt $prompt,
        string $compiled,
        string $model,
        array $variables,
        bool $isTaskMode,
        string $toolType = 'default',
    ): array {
        if ($toolType === 'video') {
            throw new PromptRunException(
                'Công cụ Video: dùng model Veo (veo-3.1-generate-preview, …) — chưa tích hợp poll async trong Prompt. '
                .'Tạm thời dán URL video vào PARENT_RESULT hoặc chọn Hình ảnh (Imagen / Nano Banana).',
            );
        }

        if ($this->mediaGeneration->shouldUseImagePipeline($prompt, $toolType)) {
            return $this->mediaGeneration->executeImage($connection, $prompt, $compiled, $variables, $model);
        }

        return match ($connection->provider) {
            'gemini' => $this->callGemini($connection, $compiled, $model),
            'claude' => $this->callClaude($prompt, $variables, $model, $isTaskMode, $compiled),
            default => throw new PromptRunException('Nhà cung cấp AI không được hỗ trợ: '.$connection->provider),
        };
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function callGemini(ApiConnection $connection, string $prompt, string $model): array
    {
        $modelsToTry = GeminiModelCatalog::modelsToTry($model);

        $lastError = null;

        foreach ($modelsToTry as $model) {
            foreach (['v1beta', 'v1'] as $apiVersion) {
                try {
                    return $this->requestGeminiGenerateContent($connection, $prompt, $model, $apiVersion);
                } catch (PromptRunException $exception) {
                    $lastError = $exception;
                    if (! $this->isGeminiModelNotFoundError($exception->getMessage())
                        && ! $this->isGeminiRetryableError($exception->getMessage())) {
                        throw $exception;
                    }
                }
            }
        }

        throw $lastError ?? new PromptRunException('Không gọi được Gemini API.');
    }

    private function isGeminiModelNotFoundError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'not found')
            || str_contains($lower, 'not supported for generatecontent')
            || str_contains($lower, '404');
    }

    private function isGeminiRetryableError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'high demand')
            || str_contains($lower, 'overloaded')
            || str_contains($lower, 'resource exhausted')
            || str_contains($lower, '429')
            || str_contains($lower, '503');
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function requestGeminiGenerateContent(
        ApiConnection $connection,
        string $prompt,
        string $model,
        string $apiVersion,
    ): array {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/%s/models/%s:generateContent',
            $apiVersion,
            rawurlencode($model),
        );

        $response = Http::timeout(180)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key])
            ->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? $response->json('error.status')
                ?? $response->body();

            throw new PromptRunException(
                'Gemini API lỗi ('.$model.', '.$apiVersion.'): '.$this->truncateError((string) $message),
            );
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if ($text === '') {
            $blockReason = $response->json('candidates.0.finishReason')
                ?? $response->json('promptFeedback.blockReason');

            throw new PromptRunException(
                'Gemini không trả về nội dung'
                .($blockReason ? ' ('.$blockReason.')' : '').'.',
            );
        }

        $usage = $response->json('usageMetadata');

        return [$text, is_array($usage) ? $usage : null];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function callClaude(
        SeoPrompt $prompt,
        array $variables,
        string $model,
        bool $isTaskMode,
        string $compiled = '',
    ): array {
        $inputData = trim((string) ($variables['input'] ?? ''));

        return $this->aiExecution->executeClaude(
            $prompt,
            $inputData !== '' ? $inputData : null,
            $isTaskMode,
            $variables,
            $model !== '' ? $model : null,
            trim($compiled) !== '' ? $compiled : null,
        );
    }

    private function truncateError(string $message): string
    {
        return mb_strlen($message) > 500 ? mb_substr($message, 0, 500).'…' : $message;
    }

    private function enforceMediaOnlyOutput(string $output, string $toolType): string
    {
        if (! in_array($toolType, ['image', 'video'], true)) {
            return $output;
        }

        $firstLine = trim(explode("\n", trim($output), 2)[0] ?? '');
        $isUrl = str_starts_with($firstLine, '/storage/') || (bool) preg_match('#^https?://#i', $firstLine);

        if (! $isUrl) {
            throw new PromptRunException(
                $toolType === 'image'
                    ? 'Hình ảnh lỗi: không nhận được file ảnh hợp lệ từ AI.'
                    : 'Video lỗi: không nhận được URL video hợp lệ từ AI.',
            );
        }

        return $firstLine;
    }
}
