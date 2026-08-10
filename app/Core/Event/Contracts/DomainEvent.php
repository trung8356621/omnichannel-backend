<?php

declare(strict_types=1);

namespace App\Core\Event\Contracts;

interface DomainEvent
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
