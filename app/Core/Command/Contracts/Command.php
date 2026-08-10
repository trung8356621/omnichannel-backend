<?php

declare(strict_types=1);

namespace App\Core\Command\Contracts;

interface Command
{
    public function name(): string;
}
