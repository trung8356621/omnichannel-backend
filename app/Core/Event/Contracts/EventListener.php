<?php

declare(strict_types=1);

namespace App\Core\Event\Contracts;

interface EventListener
{
    public function handle(DomainEvent $event): void;
}
