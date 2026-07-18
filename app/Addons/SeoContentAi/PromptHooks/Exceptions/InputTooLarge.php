<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Exceptions;

use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookFailureCode;

final class InputTooLarge extends PromptHookFailure
{
    public function __construct(string $message)
    {
        parent::__construct(PromptHookFailureCode::InputTooLarge, $message);
    }
}