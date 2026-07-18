<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Exceptions;

use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookErrorCode;
use RuntimeException;
use Throwable;

final class PromptHookException extends RuntimeException
{
    public function __construct(
        public readonly PromptHookErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCodeValue(): string
    {
        return $this->errorCode->value;
    }
}
