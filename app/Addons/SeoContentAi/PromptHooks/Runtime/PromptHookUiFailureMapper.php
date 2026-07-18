<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Runtime;

use App\Addons\SeoContentAi\PromptHooks\Exceptions\PromptHookFailure;

/** Map typed hook failures to safe UI strings (no secrets / stack / raw provider body). */
final class PromptHookUiFailureMapper
{
    /**
     * @return array{title: string, body: string, category: string}
     */
    public function map(PromptHookFailure $failure, string $hookKey = '', string $version = '', ?string $correlationId = null): array
    {
        $category = $failure->failureCode->value;
        $parts = [
            $hookKey !== '' ? "{$hookKey}@".($version !== '' ? $version : '?') : null,
            $category,
            $failure->getMessage(),
            $correlationId !== null && $correlationId !== '' ? "correlation_id={$correlationId}" : null,
        ];

        return [
            'title' => (string) __('seo-content-ai::prompt_hooks.execution_failed_title'),
            'body' => implode(' — ', array_values(array_filter($parts))),
            'category' => $category,
        ];
    }
}
