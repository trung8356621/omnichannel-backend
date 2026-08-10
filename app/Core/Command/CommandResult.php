<?php

declare(strict_types=1);

namespace App\Core\Command;

use Throwable;

final class CommandResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $payload,
        public readonly string $correlationId,
        public readonly int $durationMs,
        public readonly ?string $message = null,
        public readonly ?Throwable $exception = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function ok(array $payload, string $correlationId, int $durationMs = 0): self
    {
        return new self(true, $payload, $correlationId, $durationMs);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fail(
        string $message,
        string $correlationId,
        int $durationMs = 0,
        ?Throwable $exception = null,
        array $payload = [],
    ): self {
        return new self(false, $payload, $correlationId, $durationMs, $message, $exception);
    }
}
