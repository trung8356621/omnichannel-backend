<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Exceptions;

use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookFailureCode;

final class ProviderFailed extends PromptHookFailure
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(PromptHookFailureCode::ProviderFailed, $message, $previous);
    }
}