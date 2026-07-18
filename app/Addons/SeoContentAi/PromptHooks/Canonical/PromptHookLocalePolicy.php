<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Canonical;

final class PromptHookLocalePolicy
{
    public function __construct(
        public readonly string $mode = 'site',
        public readonly string $fallback = 'en',
        public readonly ?string $fixed = null,
    ) {}
}
