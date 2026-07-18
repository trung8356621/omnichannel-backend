<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Provider;

use App\Addons\SeoContentAi\PromptHooks\Exceptions\ProviderFailed;
use App\Addons\SeoContentAi\PromptHooks\Runtime\RenderedPromptRequest;

/** Fallback when PromptRunner adapter not bound — hook mode must fail clearly. */
final class UnconfiguredPromptProviderAdapter implements PromptProviderAdapter
{
    public function capabilities(): PromptProviderCapabilities
    {
        return new PromptProviderCapabilities(
            textGeneration: true,
            jsonMode: true,
            nativeStructuredOutput: false,
            systemMessage: true,
            temperature: true,
            maxTokens: true,
        );
    }

    public function generate(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): PromptProviderResponse
    {
        throw new ProviderFailed(
            'Prompt provider adapter not configured for hook mode. Keep migration flag legacy.',
        );
    }
}
