<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Canonical;

final class PromptHookLoggingPolicy
{
    public function __construct(
        public readonly bool $storeFullPrompt = false,
        public readonly bool $redactSensitive = true,
    ) {}
}
