<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Command\Contracts\Command;
use App\Core\Command\Contracts\CommandHandler;
use App\Core\Operations\CorrelationId;
use InvalidArgumentException;
use Throwable;

/**
 * Generic command bus foundation. Domain buses (Content Project, Agent) may wrap this.
 */
final class CommandBus
{
    /** @var array<class-string, CommandHandler> */
    private array $handlers = [];

    public function register(string $commandClass, CommandHandler $handler): void
    {
        $this->handlers[$commandClass] = $handler;
    }

    public function has(string $commandClass): bool
    {
        return isset($this->handlers[$commandClass]);
    }

    /**
     * @return list<class-string>
     */
    public function registeredCommands(): array
    {
        return array_keys($this->handlers);
    }

    public function dispatch(Command $command, ?string $correlationId = null): CommandResult
    {
        $handler = $this->handlers[$command::class] ?? null;
        if (! $handler instanceof CommandHandler) {
            throw new InvalidArgumentException('No handler for '.$command::class);
        }

        $correlationId ??= CorrelationId::currentOrNew();
        $started = microtime(true);

        try {
            $payload = $handler->handle($command);

            return CommandResult::ok(
                payload: is_array($payload) ? $payload : ['result' => $payload],
                correlationId: $correlationId,
                durationMs: (int) round((microtime(true) - $started) * 1000),
            );
        } catch (Throwable $e) {
            return CommandResult::fail(
                message: $e->getMessage(),
                correlationId: $correlationId,
                durationMs: (int) round((microtime(true) - $started) * 1000),
                exception: $e,
            );
        }
    }
}
