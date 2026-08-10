<?php

declare(strict_types=1);

namespace App\Core\Capability\Contracts;

/**
 * Publishing consumes this — never hard-codes WordPress implementation.
 */
interface PublisherCapability
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function publish(array $payload): array;

    public function supports(string $target): bool;
}
