<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;

/**
 * Sinh ảnh qua Gemini API: Imagen (:predict) hoặc Nano Banana (:generateContent).
 */
final class GeminiMediaGenerationService
{
    public function __construct(
        private readonly PromptMediaStorageService $promptMediaStorage,
    ) {}

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function generateImage(ApiConnection $connection, string $prompt, ?string $preferredModel = null): array
    {
        $prompt = $this->normalizeImagePrompt($prompt);
        $models = GoogleAiModelRegistry::imageModelsToTry($preferredModel);
        $lastError = null;

        foreach ($models as $model) {
            try {
                if (GoogleAiModelRegistry::isImagenModel($model)) {
                    return $this->requestImagenPredict($connection, $prompt, $model);
                }

                return $this->requestGeminiNativeImage($connection, $prompt, $model);
            } catch (PromptRunException $exception) {
                $lastError = $exception;
                if (! $this->isRetryable($exception->getMessage())) {
                    throw $exception;
                }
            }
        }

        throw $lastError ?? new PromptRunException('Không sinh được ảnh từ Gemini API.');
    }

    /**
     * Imagen 4 — POST .../models/{model}:predict
     *
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function requestImagenPredict(ApiConnection $connection, string $prompt, string $model): array
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:predict',
            rawurlencode($model),
        );

        $response = Http::timeout(300)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key])
            ->post($url, [
                'instances' => [
                    ['prompt' => $prompt],
                ],
                'parameters' => [
                    'sampleCount' => 1,
                    'aspectRatio' => '4:5',
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new PromptRunException(
                'Imagen API lỗi (' . $model . '): ' . $this->truncate((string) $message),
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
            $imageUrl = $this->promptMediaStorage->storeBinaryMedia($binary, $mime, 'image', $model);

            return [$imageUrl, null];
        }

        throw new PromptRunException('Imagen không trả về ảnh (' . $model . ').');
    }

    /**
     * Nano Banana — POST .../models/{model}:generateContent (v1beta).
     * Bắt buộc yêu cầu IMAGE modality, nếu không model có thể trả text-only rồi stop.
     *
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function requestGeminiNativeImage(ApiConnection $connection, string $prompt, string $model): array
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model),
        );

        $response = Http::timeout(300)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key])
            ->post($url, [
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
                'Gemini Image API lỗi (' . $model . '): ' . $this->truncate((string) $message),
            );
        }

        $parts = $response->json('candidates.0.content.parts', []);
        if (! is_array($parts)) {
            $parts = [];
        }

        $textLines = [];
        $imageUrl = null;

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

            $imageUrl = $this->promptMediaStorage->storeBinaryMedia($binary, $mime, 'image', $model);
        }

        if ($imageUrl === null) {
            $blockReason = $response->json('candidates.0.finishReason')
                ?? $response->json('promptFeedback.blockReason');
            $hint = $textLines !== []
                ? ' | text=' . mb_substr(implode(' ', $textLines), 0, 180)
                : '';

            throw new PromptRunException(
                'Gemini Image không trả ảnh (' . $model . ')'
                . ($blockReason ? ' — ' . $blockReason : '')
                . $hint
                . '. Thử Imagen 4 hoặc rút gọn prompt (≤480 token cho Imagen).',
            );
        }

        $output = $imageUrl;
        $usage = $response->json('usageMetadata');

        return [$output, is_array($usage) ? $usage : null];
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

    private function isRetryable(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'not found')
            || str_contains($lower, '404')
            || str_contains($lower, 'not supported')
            || str_contains($lower, '429')
            || str_contains($lower, '503')
            || str_contains($lower, 'high demand')
            || str_contains($lower, 'resource exhausted');
    }

    private function truncate(string $message): string
    {
        return mb_strlen($message) > 500 ? mb_substr($message, 0, 500) . '…' : $message;
    }
}
