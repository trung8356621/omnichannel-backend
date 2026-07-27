<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateContentProjectCommand implements ContentProjectCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $tasksData
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $tasksData = [],
    ) {}

    public function name(): string
    {
        return 'content_project.create';
    }
}
