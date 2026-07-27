<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Builtin\AiProviders;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiExecutionContext;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiProviderHealthResult;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiTextRequest;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiTextResult;
use App\Addons\SeoContentAi\Extension\Contracts\AiTextProviderInterface;
use App\Addons\SeoContentAi\Services\Ai\GeminiGenerateContentClient;
use App\Addons\SeoContentAi\Support\GeminiModelCatalog;
use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Models\ApiConnection;

final class GeminiAiTextProvider implements AiTextProviderInterface
{
    public function __construct(
        private readonly GeminiGenerateContentClient $client,
    ) {}

    public function key(): string
    {
        return 'gemini';
    }

    public function supportsModel(string $model): bool
    {
        $model = trim($model);
        if ($model === '') {
            return true;
        }

        return GoogleAiModelRegistry::isTextModel(GeminiModelCatalog::resolve($model));
    }

    public function generate(AiTextRequest $request, AiExecutionContext $context): AiTextResult
    {
        $connection = $this->resolveConnection($context);

        if ($connection === null) {
            return AiTextResult::failure('Không tìm thấy kết nối Gemini hợp lệ (connectionId).', $request->model);
        }

        if ($connection->provider !== 'gemini') {
            return AiTextResult::failure('Kết nối được cung cấp không phải Gemini.', $request->model);
        }

        if (blank($connection->api_key)) {
            return AiTextResult::failure('Kết nối Gemini chưa có API Key.', $request->model);
        }

        try {
            [$text, $usage] = $this->client->generate($connection, $request->prompt, $request->model);

            return AiTextResult::success($text, $request->model, $usage);
        } catch (PromptRunException $exception) {
            return AiTextResult::failure($exception->getMessage(), $request->model);
        }
    }

    public function health(AiExecutionContext $context): AiProviderHealthResult
    {
        if ($context->connectionId === null) {
            return AiProviderHealthResult::healthy('Gemini text provider registered; connection-specific health requires connectionId context.');
        }

        $connection = $this->resolveConnection($context);

        if ($connection === null) {
            return AiProviderHealthResult::unhealthy("Không tìm thấy kết nối #{$context->connectionId}.");
        }

        if ($connection->provider !== 'gemini') {
            return AiProviderHealthResult::unhealthy('Kết nối được cung cấp không phải Gemini.');
        }

        if (blank($connection->api_key)) {
            return AiProviderHealthResult::unhealthy('Kết nối Gemini chưa có API Key.');
        }

        return AiProviderHealthResult::healthy('Kết nối Gemini hợp lệ.');
    }

    private function resolveConnection(AiExecutionContext $context): ?ApiConnection
    {
        if ($context->connectionId === null) {
            return null;
        }

        return ApiConnection::query()->find($context->connectionId);
    }
}
