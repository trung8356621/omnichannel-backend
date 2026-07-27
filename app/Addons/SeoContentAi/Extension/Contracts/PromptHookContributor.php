<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Contracts;

interface PromptHookContributor
{
    /**
     * @return list<array{key: string, version: string, meta: array<string, mixed>}>
     */
    public function hooks(): array;
}
