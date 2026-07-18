<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Exceptions;

use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookFailureCode;

final class InvalidSectionOutput extends PromptHookFailure
{
    public function __construct(
        string $message,
        public readonly string $hookKey = '',
        public readonly string $hookVersion = '',
        public readonly string $sectionKey = '',
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct(PromptHookFailureCode::InvalidSectionOutput, $message);
    }
}
