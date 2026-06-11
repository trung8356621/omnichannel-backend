<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Support\AiModelCategory;
use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Addons\SeoContentAi\Support\PromptLoaiSanPhamVariable;
use App\Addons\SeoContentAi\Support\Utf8Sanitizer;
use App\Models\ApiConnection;

/**
 * Trung chuyển thực thi AI theo công cụ Prompt — tách Imagen/Gemini Image khỏi Claude Text.
 */
final class MediaGenerationService
{
    public function __construct(
        private readonly GeminiMediaGenerationService $geminiMediaGeneration,
        private readonly AiModelRouterService $aiModelRouter,
    ) {}

    /**
     * Điểm vào chính: phân luồng image vs text (Claude).
     *
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function execute(
        SeoPrompt $prompt,
        array $variables,
        ?string $inputData = null,
        bool $isTaskMode = true,
        ?string $compiledPrompt = null,
        ?string $modelOverride = null,
        string $effectiveToolType = 'default',
    ): array {
        $prompt->loadMissing(['aiConnection']);
        $variables = Utf8Sanitizer::variablesForAi($variables);

        if ($this->shouldUseImagePipeline($prompt, $effectiveToolType)) {
            return $this->executeImagePipeline(
                $prompt,
                $variables,
                $inputData,
                $compiledPrompt,
                $modelOverride,
            );
        }

        $connection = $prompt->aiConnection;
        if ($connection === null || $connection->provider !== 'claude') {
            throw new PromptRunException(
                'Văn bản Gemini chạy qua PromptRunner; MediaGenerationService::execute() chỉ phân luồng ảnh → Imagen hoặc Claude.',
            );
        }

        return app(AiExecutionService::class)->executeClaude(
            $prompt,
            $inputData,
            $isTaskMode,
            $variables,
            $modelOverride,
            $compiledPrompt,
        );
    }

    /**
     * Chỉ khi bước hiện tại là image (sub_task hoặc prompt một bước tools=image).
     * Bước task cha trong chuỗi luôn effectiveTool = default → không vào Imagen.
     */
    public function shouldUseImagePipeline(SeoPrompt $prompt, string $effectiveToolType): bool
    {
        return $effectiveToolType === 'image';
    }

    public function isPromptImageTool(SeoPrompt $prompt): bool
    {
        return $this->normalizePromptTool($prompt) === 'image';
    }

    /**
     * Sinh ảnh một lần (Imagen / Nano Banana) — dùng từ PromptRunner::callProvider.
     *
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function executeImage(
        ApiConnection $connection,
        SeoPrompt $prompt,
        string $compiled,
        array $variables,
        ?string $routedModel = null,
    ): array {
        if ($connection->provider !== 'gemini') {
            throw new PromptRunException(
                'Công cụ Hình ảnh cần kết nối Gemini (Imagen 4 hoặc Nano Banana). '
                . 'AiExecutionService (Claude) chỉ dùng cho văn bản.',
            );
        }

        $imagePrompt = $this->buildImageGenerationInput($compiled, $variables);
        $excludeImagen = $this->isProductImageContext($variables);
        $imageModel = $excludeImagen ? null : $this->resolveImageModelSlug($connection, $routedModel);
        [$output, $usage] = $this->geminiMediaGeneration->generateImage(
            $connection,
            $imagePrompt,
            $imageModel,
            excludeImagen: $excludeImagen,
        );

        $firstLine = trim(explode("\n", trim($output), 2)[0] ?? '');
        if (! str_starts_with($firstLine, '/storage/')) {
            throw new PromptRunException(
                'Hình ảnh lỗi: model không trả file ảnh hợp lệ (' . ($imageModel ?? 'nano-banana-auto') . ').',
            );
        }

        return [$output, $usage];
    }

    /**
     * Ảnh sản phẩm (post_type = product / có loai_san_pham, gallery_description):
     * Imagen render chữ trong prompt thành ảnh text → chỉ dùng Nano Banana.
     *
     * @param  array<string, string>  $variables
     */
    private function isProductImageContext(array $variables): bool
    {
        if (trim((string) ($variables['post_type'] ?? '')) === 'product') {
            return true;
        }

        foreach (['loai_san_pham', 'LOAI_SAN_PHAM', 'gallery_description', PromptLoaiSanPhamVariable::CUSTOM_FIELD] as $key) {
            if (trim((string) ($variables[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * API cho ImageGenerationChainService / gọi từ bên ngoài khi tools = image.
     *
     * @param  array<string, string>  $variables
     * @return array<string, mixed>|string
     */
    public function generate(SeoPrompt $prompt, array $variables = [], ?string $inputData = null): array|string
    {
        $prompt->loadMissing(['aiConnection']);
        $variables = Utf8Sanitizer::variablesForAi($variables);

        if ($prompt->aiConnection === null) {
            throw new PromptRunException('Prompt chưa được gắn kết nối AI.');
        }

        if ($inputData !== null && trim($inputData) !== '') {
            $variables['input'] = Utf8Sanitizer::compactForAiVariable($inputData);
        }

        if (app(PromptRunnerService::class)->hasDependentSubTasks($prompt)) {
            return app(ImageGenerationChainService::class)->generateImageChain($prompt, $variables);
        }

        $compiled = app(PromptRunnerService::class)->compilePrompt($prompt, $variables);
        [$output] = $this->executeImage(
            $prompt->aiConnection,
            $prompt,
            $compiled,
            $variables,
            null,
        );

        return $output;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function executeImagePipeline(
        SeoPrompt $prompt,
        array $variables,
        ?string $inputData,
        ?string $compiledPrompt,
        ?string $modelOverride,
    ): array {
        $connection = $prompt->aiConnection;
        $variables = Utf8Sanitizer::variablesForAi($variables);
        if ($connection === null) {
            throw new PromptRunException('Prompt chưa được gắn kết nối AI.');
        }

        if ($connection->provider !== 'gemini') {
            throw new PromptRunException(
                'Prompt «Hình ảnh» yêu cầu kết nối Gemini. Không gọi Claude/AiExecutionService cho sinh ảnh.',
            );
        }

        $compiled = trim((string) $compiledPrompt);
        if ($compiled === '') {
            $compiled = app(PromptRunnerService::class)->compilePrompt($prompt, $variables);
        }

        if ($inputData !== null && trim($inputData) !== '') {
            $variables['input'] = Utf8Sanitizer::compactForAiVariable($inputData);
        }

        return $this->executeImage($connection, $prompt, $compiled, $variables, $modelOverride);
    }

    private function resolveImageModelSlug(ApiConnection $connection, ?string $routedModel): string
    {
        $routedModel = trim((string) $routedModel);
        if ($routedModel !== '' && ! GoogleAiModelRegistry::isTextModel($routedModel)) {
            return GoogleAiModelRegistry::normalizeSlug($routedModel);
        }

        $active = $this->aiModelRouter->getActiveModel(
            (int) $connection->id,
            AiModelCategory::IMAGEN_PRO,
        );

        if ($active !== null) {
            return GoogleAiModelRegistry::normalizeSlug((string) $active->raw_model_name);
        }

        return 'imagen-4.0-fast-generate-001';
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function buildImageGenerationInput(string $compiled, array $variables): string
    {
        $parent = trim((string) ($variables['PARENT_RESULT'] ?? ''));
        if ($parent !== '' && ! str_starts_with($parent, '/storage/')) {
            $parent = Utf8Sanitizer::string(mb_substr($parent, 0, 1800));

            return Utf8Sanitizer::string(
                "Generate exactly ONE image. Do not output markdown or explanation.\n\n"
                . "Use the following brief from previous step as context:\n"
                . $parent
                . "\n\nRender instructions for this step:\n"
                . $compiled,
            );
        }

        return Utf8Sanitizer::string(
            "Generate exactly ONE image. Do not write instructions, Midjourney prompts, or markdown — output the image.\n\n"
            . "Visual specification:\n\n"
            . $compiled,
        );
    }

    private function normalizePromptTool(SeoPrompt $prompt): string
    {
        $tool = trim((string) ($prompt->tools ?? 'default'));

        return in_array($tool, ['default', 'image', 'video'], true) ? $tool : 'default';
    }
}
