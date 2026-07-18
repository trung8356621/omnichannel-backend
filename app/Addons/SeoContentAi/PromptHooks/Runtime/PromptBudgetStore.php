<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Runtime;

interface PromptBudgetStore
{
    /**
     * @return array{requests: int, tokens: int}
     */
    public function get(string $bucket): array;

    public function increment(string $bucket, int $tokens): void;
}
