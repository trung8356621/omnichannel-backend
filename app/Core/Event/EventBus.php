<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Event\Contracts\DomainEvent;
use App\Core\Event\Contracts\EventListener;

/**
 * Synchronous in-process event bus foundation.
 * Async/queue fan-out can wrap listeners later.
 */
final class EventBus
{
    /** @var array<string, list<EventListener>> */
    private array $listeners = [];

    public function listen(string $eventName, EventListener $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        $name = $event->name();
        foreach ($this->listeners[$name] ?? [] as $listener) {
            $listener->handle($event);
        }
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_keys($this->listeners);
    }

    public function listenerCount(string $eventName): int
    {
        return count($this->listeners[$eventName] ?? []);
    }
}
