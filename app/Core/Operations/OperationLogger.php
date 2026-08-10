<?php

declare(strict_types=1);

namespace App\Core\Operations;

use App\Support\RuntimeLogger;

/**
 * Basic operation logging primitive — not business dashboards.
 */
final class OperationLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $operation, array $context = []): void
    {
        $this->write('info', $operation, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $operation, array $context = []): void
    {
        $this->write('warning', $operation, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $operation, array $context = []): void
    {
        $this->write('error', $operation, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $operation, array $context): void
    {
        $context['correlation_id'] = $context['correlation_id'] ?? CorrelationId::currentOrNew();
        $context['operation'] = $operation;

        if (class_exists(RuntimeLogger::class)) {
            match ($level) {
                'warning' => RuntimeLogger::warning('core.operation.'.$operation, $context),
                'error' => RuntimeLogger::error('core.operation.'.$operation, $context),
                default => RuntimeLogger::info('core.operation.'.$operation, $context),
            };

            return;
        }

        // Pure PHPUnit / early boot — noop.
    }
}
