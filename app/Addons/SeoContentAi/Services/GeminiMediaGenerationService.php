<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoAiModel;
use App\Addons\SeoContentAi\Support\GeminiModelVersionPolicy;
use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Addons\SeoContentAi\Support\ImageRoutingStrategy;
use App\Addons\SeoContentAi\Support\ImageToolType;
use App\Addons\SeoContentAi\Support\RenderingPreference;
use App\Addons\SeoContentAi\Support\TypographyComplexity;
use App\Addons\SeoContentAi\Support\Utf8Sanitizer;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;

/**
 * Sinh ảnh qua Gemini API: Imagen (:predict) hoặc Nano Banana (:generateContent).
 * Danh sách model thử đến từ ImageRoutingStrategy (entry duy nhất).
 */
final class GeminiMediaGenerationService
{
    /** Per-model HTTP budget — must stay below GenerateMediaJob queue timeout. */
    private const HTTP_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly PromptMediaStorageService $promptMediaStorage,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly ImageRoutingStrategy $imageRoutingStrategy,
    ) {}

    /**
     * @param  list<string>|null  $modelsOverride  Nếu có (vd. từ executionPolicy fallback) — dùng trực tiếp, không gọi lại modelsToTry
     * @return array{url: string, usage: array<string, mixed>|null, model_used: string}
     */
    public function generateImage(
        ApiConnection $connection,
        string $prompt,
        ImageToolType $toolType = ImageToolType::Image,
        ?RenderingPreference $preference = null,
        bool $productContext = false,
        ?int $inputLength = null,
        ?TypographyComplexity $typographyComplexity = null,
        ?array $modelsOverride = null,
    ): array {
        $rendered = $this->generateImageBinary(
            connection: $connection,
            prompt: $prompt,
            toolType: $toolType,
            preference: $preference,
            productContext: $productContext,
            inputLength: $inputLength,
            typographyComplexity: $typographyComplexity,
            modelsOverride: $modelsOverride,
        );

        $imageUrl = $this->promptMediaStorage->storeBinaryMedia(
            $rendered['binary'],
            $rendered['mime'],
            'image',
            $rendered['model_used'],
        );

        logger()->info('Image render succeeded', [
            'render_model' => $rendered['model_used'],
            'tool_type' => $toolType->value,
        ]);

        return [
            'url' => $imageUrl,
            'usage' => $rendered['usage'],
            'model_used' => $rendered['model_used'],
        ];
    }

    /**
     * Render ảnh → binary only (không ghi seo_media). Dùng cho typography candidate.
     *
     * @param  list<string>|null  $modelsOverride
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    public function generateImageBinary(
        ApiConnection $connection,
        string $prompt,
        ImageToolType $toolType = ImageToolType::Image,
        ?RenderingPreference $preference = null,
        bool $productContext = false,
        ?int $inputLength = null,
        ?TypographyComplexity $typographyComplexity = null,
        ?array $modelsOverride = null,
    ): array {
        $prompt = $this->normalizeImagePrompt(Utf8Sanitizer::string($prompt));
        $preference ??= $this->workflowSettings->getRenderingPreference();

        if ($modelsOverride !== null) {
            $models = array_values(array_unique(array_filter(array_map(
                static fn (mixed $slug): string => GoogleAiModelRegistry::normalizeSlug((string) $slug),
                $modelsOverride,
            ))));
        } elseif ($toolType->isTypography()) {
            $policy = $this->imageRoutingStrategy->executionPolicy(
                toolType: ImageToolType::ImageTypography,
                preference: $preference,
                typographyComplexity: $typographyComplexity,
                compiledPromptLength: $inputLength,
                productContext: $productContext,
                configuredPriorityList: $this->workflowSettings->getTypographyModelPriority(),
                adminEnabledUnknownSlugs: $this->workflowSettings->getAdminEnabledUnknownImageModels(),
                allowGeneralImageFallback: $this->workflowSettings->allowTypographyGeneralImageFallback(),
                generalImageFallbackPriorityList: $this->workflowSettings->getImageModelPriority(),
            );
            $models = $policy->models;
        } else {
            $models = $this->imageRoutingStrategy->modelsToTry(
                toolType: $toolType,
                preference: $preference,
                compiledPromptLength: $inputLength,
                productContext: $productContext,
                typographyComplexity: $typographyComplexity,
                configuredPriorityList: $this->workflowSettings->getImageModelPriority(),
                adminEnabledUnknownSlugs: $this->workflowSettings->getAdminEnabledUnknownImageModels(),
            );
        }

        if ($models === []) {
            throw new PromptRunException(
                $toolType->isTypography()
                    ? (
                        $this->workflowSettings->allowTypographyGeneralImageFallback()
                            ? 'Không có model image (typography hoặc general) đủ điều kiện để render.'
                            : 'Không có model typography phù hợp. Bật General Image Fallback trong AI Advanced hoặc thêm model typography_supported.'
                    )
                    : 'Không có model image đủ điều kiện để render.',
            );
        }

        $lastError = null;

        foreach ($models as $model) {
            try {
                $rendered = GoogleAiModelRegistry::isImagenModel($model)
                    ? $this->requestImagenPredict($connection, $prompt, $model)
                    : $this->requestGeminiNativeImage($connection, $prompt, $model);

                logger()->info('Image render binary succeeded', [
                    'render_model' => $rendered['model_used'] ?? $model,
                    'tool_type' => $toolType->value,
                ]);

                return $rendered;
            } catch (PromptRunException $exception) {
                $lastError = $exception;
                $this->handleRenderModelFailure($connection, $model, $exception->getMessage());
                if (! $this->isRetryable($exception->getMessage())) {
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                $lastError = new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
                $this->handleRenderModelFailure($connection, $model, $exception->getMessage());
                if (! $this->isRetryable($exception->getMessage())) {
                    throw $lastError;
                }
            }
        }

        throw $lastError ?? new PromptRunException('Không sinh được ảnh từ Gemini API.');
    }

    private function handleRenderModelFailure(ApiConnection $connection, string $model, string $message): void
    {
        logger()->warning('Image render model failed, try next', [
            'render_model' => $model,
            'error' => $message,
        ]);

        if (! GeminiModelVersionPolicy::isProviderUnavailableError($message)) {
            return;
        }

        $record = SeoAiModel::query()
            ->where('api_connection_id', (int) $connection->id)
            ->where('raw_model_name', $model)
            ->first();

        if (! $record instanceof SeoAiModel) {
            return;
        }

        $capabilities = is_array($record->capabilities) ? $record->capabilities : [];
        $record->update([
            'capabilities' => GeminiModelVersionPolicy::markCapabilitiesUnavailable($capabilities, $message),
            'last_error' => mb_substr($message, 0, 2000),
        ]);
    }

    /**
     * Imagen 4 — POST .../models/{model}:predict
     *
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    private function requestImagenPredict(ApiConnection $connection, string $prompt, string $model): array
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:predict',
            rawurlencode($model),
        );

        $response = $this->geminiHttpClient($connection)->post($url, [
            'instances' => [
                ['prompt' => $prompt],
            ],
            'parameters' => [
                'sampleCount' => 1,
                'aspectRatio' => $this->resolveImagenAspectRatio($prompt),
            ],
        ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new PromptRunException(
                'Imagen API lỗi ('.$model.'): '.$this->truncate((string) $message),
            );
        }

        $predictions = $response->json('predictions', []);
        if (! is_array($predictions)) {
            $predictions = [];
        }

        foreach ($predictions as $prediction) {
            if (! is_array($prediction)) {
                continue;
            }

            $b64 = (string) ($prediction['bytesBase64Encoded'] ?? $prediction['bytes_base64_encoded'] ?? '');
            if ($b64 === '') {
                continue;
            }

            $binary = base64_decode($b64, true);
            if ($binary === false || $binary === '') {
                continue;
            }

            $mime = (string) ($prediction['mimeType'] ?? 'image/png');

            return [
                'binary' => $binary,
                'mime' => $mime !== '' ? $mime : 'image/png',
                'usage' => null,
                'model_used' => $model,
            ];
        }

        throw new PromptRunException('Imagen không trả về ảnh ('.$model.').');
    }

    /**
     * Nano Banana — POST .../models/{model}:generateContent (v1beta).
     * Bắt buộc yêu cầu IMAGE modality, nếu không model có thể trả text-only rồi stop.
     *
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    private function requestGeminiNativeImage(ApiConnection $connection, string $prompt, string $model): array
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model),
        );

        $response = $this->geminiHttpClient($connection)->post($url, [
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new PromptRunException(
                'Gemini Image API lỗi ('.$model.'): '.$this->truncate((string) $message),
            );
        }

        $parts = $response->json('candidates.0.content.parts', []);
        if (! is_array($parts)) {
            $parts = [];
        }

        $textLines = [];
        $binaryOut = null;
        $mimeOut = 'image/png';

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (filled($part['text'] ?? null)) {
                $textLines[] = trim((string) $part['text']);
            }

            $inline = is_array($part['inlineData'] ?? null) ? $part['inlineData'] : null;
            if ($inline === null || blank($inline['data'] ?? null)) {
                continue;
            }

            $mime = (string) ($inline['mimeType'] ?? 'image/png');
            $binary = base64_decode((string) $inline['data'], true);
            if ($binary === false || $binary === '') {
                continue;
            }

            $binaryOut = $binary;
            $mimeOut = $mime !== '' ? $mime : 'image/png';
        }

        if ($binaryOut === null) {
            $blockReason = $response->json('candidates.0.finishReason')
                ?? $response->json('promptFeedback.blockReason');
            $hint = $textLines !== []
                ? ' | text='.mb_substr(implode(' ', $textLines), 0, 180)
                : '';

            throw new PromptRunException(
                'Gemini Image không trả ảnh ('.$model.')'
                .($blockReason ? ' — '.$blockReason : '')
                .$hint
                .'. Thử Imagen 4 hoặc rút gọn prompt (≤480 token cho Imagen).',
            );
        }

        $usage = $response->json('usageMetadata');

        return [
            'binary' => $binaryOut,
            'mime' => $mimeOut,
            'usage' => is_array($usage) ? $usage : null,
            'model_used' => $model,
        ];
    }

    private function normalizeImagePrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new PromptRunException('Prompt sinh ảnh trống.');
        }

        // Imagen giới hạn ~480 token — ưu tiên đoạn tiếng Anh / prompt thuần nếu quá dài.
        if (mb_strlen($prompt) > 3500) {
            if (preg_match('/```[\s\S]*?```/m', $prompt, $code) === 1) {
                $prompt = trim($code[0], "` \n");
            } elseif (preg_match('/(?:Main Prompt|PROMPT|Prompt)[^\n]*\n+([\s\S]{200,2500})/i', $prompt, $match) === 1) {
                $prompt = trim($match[1]);
            } else {
                $prompt = mb_substr($prompt, 0, 3500);
            }
        }

        return $prompt;
    }

    private function resolveImagenAspectRatio(string $prompt): string
    {
        $normalized = mb_strtolower($prompt);

        if (
            str_contains($normalized, '2x3')
            || str_contains($normalized, '2 x 3')
            || str_contains($normalized, '2 dòng 3 cột')
            || str_contains($normalized, '2 hàng, 3 cột')
            || str_contains($normalized, '2 rows')
        ) {
            return '4:3';
        }

        if (
            str_contains($normalized, 'landscape')
            || str_contains($normalized, 'horizontal')
            || str_contains($normalized, '16:9')
        ) {
            return '16:9';
        }

        if (
            str_contains($normalized, 'portrait')
            || str_contains($normalized, 'vertical')
            || str_contains($normalized, '9:16')
        ) {
            return '3:4';
        }

        return '3:4';
    }

    private function geminiHttpClient(ApiConnection $connection): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->connectTimeout(30)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key]);
    }

    private function isRetryable(string $message): bool
    {
        $lower = strtolower($message);

        return GeminiModelVersionPolicy::isProviderUnavailableError($message)
            || str_contains($lower, 'not found')
            || str_contains($lower, '404')
            || str_contains($lower, 'not supported')
            || str_contains($lower, '429')
            || str_contains($lower, '503')
            || str_contains($lower, 'high demand')
            || str_contains($lower, 'resource exhausted')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'curl error 28')
            || str_contains($lower, 'connection')
            || str_contains($lower, 'could not resolve');
    }

    private function truncate(string $message): string
    {
        return mb_strlen($message) > 500 ? mb_substr($message, 0, 500).'…' : $message;
    }
}
