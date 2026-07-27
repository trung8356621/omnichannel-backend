<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;

interface ContentProjectCommandHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult;
}
