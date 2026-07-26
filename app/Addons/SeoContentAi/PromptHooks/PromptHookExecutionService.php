<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\PromptHooks\Data\PromptHookDefinition;
use App\Addons\SeoContentAi\PromptHooks\Data\PromptHookExecutionResult;
use App\Addons\SeoContentAi\PromptHooks\Entities\ArticlePromptHookEntityResolver;
use App\Addons\SeoContentAi\PromptHooks\Exceptions\PromptHookException;
use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookErrorCode;
use App\Addons\SeoContentAi\Services\PromptResultAttachService;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use App\Addons\SeoContentAi\Support\ImageToolType;

final class PromptHookExecutionService
{
    public function __construct(
        private readonly PromptHookRegistry $registry,
        private readonly ArticlePromptHookEntityResolver $articleResolver,
        private readonly PromptHookInputResolver $inputResolver,
        private readonly PromptHookSettingsResolver $settingsResolver,
        private readonly PromptHookPromptAssembler $promptAssembler,
        private readonly PromptHookOutputNormalizer $outputNormalizer,
        private readonly PromptRunnerService $promptRunner,
        private readonly PromptResultAttachService $promptResultAttach,
    ) {}

    /**
     * @param  array<string, mixed>  $runtimeInput
     */
    public function execute(
        string $hookKey,
        int $articleId,
        array $runtimeInput = [],
        ?SeoPrompt $prompt = null,
    ): PromptHookExecutionResult {
        $definition = $this->registry->get($hookKey);
        $article = $this->articleResolver->loadAuthorized($articleId);
        $entityContext = $this->articleResolver->buildContext($article);

        $prompt ??= $this->resolveConfiguredPrompt($definition);
        $this->assertPromptMatchesHook($prompt, $definition);
        $this->assertPromptModelSupported($prompt, $definition);

        $resolvedInput = $this->inputResolver->resolve($definition, $runtimeInput, $entityContext);
        $exposedInput = $this->inputResolver->exposeToPrompt($definition, $resolvedInput);
        $resolvedSettings = $this->settingsResolver->resolve(
            $definition,
            is_array($prompt->hook_settings) ? $prompt->hook_settings : null,
        );

        $assembled = $this->promptAssembler->assemble(
            $definition,
            $prompt,
            $exposedInput,
            $resolvedSettings,
        );

        try {
            $result = $this->promptRunner->runWithCompiledPrompt(
                $prompt,
                $assembled['final_prompt'],
                $assembled['variables'],
            );
        } catch (PromptRunException $exception) {
            throw new PromptHookException(
                PromptHookErrorCode::HookExecutionFailed,
                $exception->getMessage(),
                $exception,
            );
        } catch (\Throwable $exception) {
            throw new PromptHookException(
                PromptHookErrorCode::HookExecutionFailed,
                'Prompt hook execution failed.',
                $exception,
            );
        }

        // Orchestrator attach via domain service (same boundary as Action prompt_result.attach).
        // Hook Runtime Engine must never call this path.
        $this->attachPromptResultAfterExecution($article, $definition, $prompt, $result);

        $raw = trim((string) ($result->output_text ?? ''));
        $output = $this->outputNormalizer->normalize($definition, $raw);

        return new PromptHookExecutionResult(
            hook: $definition->key,
            output: $output,
            promptResultId: $result->id !== null ? (int) $result->id : null,
        );
    }

    private function attachPromptResultAfterExecution(
        \App\Addons\SeoContentAi\Models\SeoArticle $article,
        PromptHookDefinition $definition,
        SeoPrompt $prompt,
        PromptResult $result,
    ): void {
        $resultId = (int) $result->getKey();
        $articleId = (int) $article->getKey();
        if ($resultId <= 0 || $articleId <= 0) {
            return;
        }

        $stepTitle = trim((string) ($prompt->name ?? ''));
        if ($stepTitle === '') {
            $stepTitle = 'Prompt Hook: '.$definition->key;
        }

        $this->promptResultAttach->attach(
            promptResultId: $resultId,
            targetType: PromptResultAttachService::TARGET_ARTICLE,
            targetId: $articleId,
            siteId: (int) ($article->site_id ?? 0),
            purpose: 'prompt_hook',
            meta: [
                'hook_key' => $definition->key,
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'status' => (string) ($result->status ?? ''),
                'workflow_step_title' => $stepTitle,
            ],
        );
    }

    /**
     * Resolve + validate input without calling AI (tests / dry-run).
     *
     * @param  array<string, mixed>  $runtimeInput
     * @return array{definition: PromptHookDefinition, resolved_input: array<string, mixed>, exposed_input: array<string, mixed>, entity_context: array<string, mixed>}
     */
    public function resolveOnly(
        string $hookKey,
        int $articleId,
        array $runtimeInput = [],
    ): array {
        $definition = $this->registry->get($hookKey);
        $article = $this->articleResolver->loadAuthorized($articleId);
        $entityContext = $this->articleResolver->buildContext($article);
        $resolvedInput = $this->inputResolver->resolve($definition, $runtimeInput, $entityContext);
        $exposedInput = $this->inputResolver->exposeToPrompt($definition, $resolvedInput);

        return [
            'definition' => $definition,
            'resolved_input' => $resolvedInput,
            'exposed_input' => $exposedInput,
            'entity_context' => $entityContext,
            'article' => $article,
        ];
    }

    public function resolveConfiguredPrompt(PromptHookDefinition $definition): SeoPrompt
    {
        return app(\App\Addons\SeoContentAi\Services\PromptOwnership\PromptBindingResolver::class)
            ->resolveSettingsHook($definition->key);
    }

    private function assertPromptMatchesHook(SeoPrompt $prompt, PromptHookDefinition $definition): void
    {
        $hookKey = trim((string) ($prompt->hook_key ?? ''));
        if ($hookKey === '' || $hookKey !== $definition->key) {
            throw new PromptHookException(
                PromptHookErrorCode::HookPromptMismatch,
                "Prompt #{$prompt->id} hook_key does not match [{$definition->key}].",
            );
        }
    }

    private function assertPromptModelSupported(SeoPrompt $prompt, PromptHookDefinition $definition): void
    {
        if ($definition->capability() !== 'text') {
            return;
        }

        if (ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline()) {
            throw new PromptHookException(
                PromptHookErrorCode::HookModelUnsupported,
                "Hook [{$definition->key}] requires a text capability prompt.",
            );
        }
    }
}
