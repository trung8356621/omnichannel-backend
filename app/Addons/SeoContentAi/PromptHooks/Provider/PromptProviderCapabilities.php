<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Provider;

final class PromptProviderCapabilities
{
    public function __construct(
        public readonly bool $textGeneration = true,
        public readonly bool $jsonMode = false,
        public readonly bool $nativeStructuredOutput = false,
        public readonly bool $systemMessage = true,
        public readonly bool $temperature = true,
        public readonly bool $maxTokens = true,
    ) {}
}
