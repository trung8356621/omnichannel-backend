<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Platform\Data;

final class MenuItemDefinition
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly ?string $route = null,
        public readonly ?string $group = null,
        public readonly int $sort = 0,
        public readonly array $meta = [],
    ) {}
}
