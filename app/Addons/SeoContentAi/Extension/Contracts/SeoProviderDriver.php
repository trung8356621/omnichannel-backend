<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Contracts;

interface SeoProviderDriver
{
    public function id(): string;

    public function label(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array;
}
