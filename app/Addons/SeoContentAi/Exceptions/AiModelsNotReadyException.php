<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Exceptions;

final class AiModelsNotReadyException extends PromptRunException
{
    public function __construct(
        string $message,
        private readonly string $overviewUrl,
    ) {
        parent::__construct($message);
    }

    public function overviewUrl(): string
    {
        return $this->overviewUrl;
    }
}
