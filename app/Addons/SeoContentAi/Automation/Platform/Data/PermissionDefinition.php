<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Platform\Data;

final class PermissionDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly string $description = '',
    ) {}
}
