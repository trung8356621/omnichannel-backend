<?php

declare(strict_types=1);

namespace App\Core\Command\Contracts;

interface CommandHandler
{
    /**
     * @return mixed
     */
    public function handle(Command $command): mixed;
}
