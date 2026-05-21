<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Support\AiModelCatalog;
use App\Addons\SeoContentAi\Support\GeminiModelCatalog;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoPromptPart;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;

final class PromptRunnerService
{
    private const ROLE_HEADINGS = [
        'role' => 'Vai trò',
        'context' => 'Bối cảnh',
        'task' => 'Nhiệm vụ',
        'formatting' => 'Định dạng đầu ra',
        'constraints' => 'Ràng buộc',
    ];

    /**
     * @param  array<string, string>  $variables
     */
    public function run(SeoPrompt $prompt, array $variables, ?string $modelOverride = null): PromptResult
    {
        $prompt->loadMissing(['parts', 'aiConnection']);

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

        $model = trim((string) ($modelOverride ?? ''));
        if ($model === '') {
            $model = AiModelCatalog::defaultForConnection($connection);
        }

        $compiled = $this->compilePrompt($prompt, $variables);

        $result = PromptResult::query()->create([
            'prompt_id' => $prompt->id,
            'entity_id' => null,
            'user_id' => (int) auth()->id(),
            'site_id' => 0,
            'status' => 'running',
            'input_snapshot' => [
                'variables' => $variables,
                'compiled_prompt' => $compiled,
                'model' => $model,
            ],
            'started_at' => now(),
        ]);

        try {
            [$output, $usage] = $this->callProvider($connection, $compiled, $model);

            $result->update([
                'status' => 'completed',
                'output_text' => $output,
                'token_usage' => $usage,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $result->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
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
     */
    public function compilePrompt(SeoPrompt $prompt, array $variables): string
    {
        $parts = $prompt->parts()->orderBy('position')->get();
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

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function formatPartBlock(SeoPromptPart $part, array $variables): string
    {
        $content = trim($this->substituteVariables((string) $part->content, $variables));
        if ($content === '') {
            return '';
        }

        $heading = self::ROLE_HEADINGS[$part->role] ?? ucfirst((string) $part->role);
        if ($part->role === 'task' && filled($part->name)) {
            $heading .= ': ' . $part->name;
        }

        $lines = ["## {$heading}", $content];

        $meta = is_array($part->meta) ? $part->meta : [];
        $rules = trim((string) ($meta['rules'] ?? ''));
        if ($rules !== '') {
            $lines[] = '';
            $lines[] = 'Quy tắc:';
            $lines[] = $this->substituteVariables($rules, $variables);
        }

        return implode("\n", $lines);
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
                    ? (string) $variables[$key]
                    : $matches[0];
            },
            $text,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function callProvider(ApiConnection $connection, string $prompt, string $model): array
    {
        return match ($connection->provider) {
            'gemini' => $this->callGemini($connection, $prompt, $model),
            'claude' => $this->callClaude($connection, $prompt, $model),
            default => throw new PromptRunException('Nhà cung cấp AI không được hỗ trợ: ' . $connection->provider),
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
                'Gemini API lỗi (' . $model . ', ' . $apiVersion . '): ' . $this->truncateError((string) $message),
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
                . ($blockReason ? ' (' . $blockReason . ')' : '') . '.',
            );
        }

        $usage = $response->json('usageMetadata');

        return [$text, is_array($usage) ? $usage : null];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function callClaude(ApiConnection $connection, string $prompt, string $model): array
    {
        $model = $model !== '' ? $model : ($connection->default_model ?: 'claude-3-5-sonnet-20240620');

        $response = Http::timeout(180)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $connection->api_key,
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 8192,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? $response->json('error.type')
                ?? $response->body();

            throw new PromptRunException('Claude API lỗi: ' . $this->truncateError((string) $message));
        }

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if ($text === '') {
            throw new PromptRunException('Claude không trả về nội dung.');
        }

        $usage = $response->json('usage');

        return [$text, is_array($usage) ? $usage : null];
    }

    private function truncateError(string $message): string
    {
        return mb_strlen($message) > 500 ? mb_substr($message, 0, 500) . '…' : $message;
    }
}
