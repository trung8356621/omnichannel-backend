<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Exceptions;

use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookFailureCode;
use RuntimeException;
use Throwable;

/** Typed Prompt Hook failure — không dùng generic RuntimeException cho mọi case. */
class PromptHookFailure extends RuntimeException
{
    public function __construct(
        public readonly PromptHookFailureCode $failureCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
