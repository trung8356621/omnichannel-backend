<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts;

interface ContentProjectCommand
{
    public function name(): string;
}
