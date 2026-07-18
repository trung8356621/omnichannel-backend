<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Exceptions;

use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookFailureCode;

final class ExperimentalNotAllowed extends PromptHookFailure
{
    public function __construct(string $key, string $version)
    {
        parent::__construct(
            PromptHookFailureCode::ExperimentalNotAllowed,
            "Experimental prompt hook not allowed [{$key}@{$version}].",
        );
    }
}