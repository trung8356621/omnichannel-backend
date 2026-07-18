<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Provider;

use App\Addons\SeoContentAi\PromptHooks\Canonical\PromptHookDefinition;
use App\Addons\SeoContentAi\PromptHooks\Exceptions\UnsupportedProviderCapability;

enum PromptStructuredStrategy: string
{
    case NativeSchema = 'native_schema';
    case JsonMode = 'json_mode';
    case PromptEnforcedJson = 'prompt_enforced_json';
    case PlainText = 'plain_text';
}

final class PromptProviderCapabilityResolver
{
    public function resolveStrategy(
        PromptHookDefinition $definition,
        PromptProviderCapabilities $capabilities,
    ): PromptStructuredStrategy {
        $wantsJson = in_array($definition->outputSchema->type, ['json', 'structured_object'], true)
            || $definition->model->structuredOutput;

        if (! $capabilities->textGeneration) {
            throw new UnsupportedProviderCapability('Provider lacks text generation.');
        }

        if (! $wantsJson) {
            return PromptStructuredStrategy::PlainText;
        }

        if ($capabilities->nativeStructuredOutput) {
            return PromptStructuredStrategy::NativeSchema;
        }
        if ($capabilities->jsonMode) {
            return PromptStructuredStrategy::JsonMode;
        }

        return PromptStructuredStrategy::PromptEnforcedJson;
    }
}
