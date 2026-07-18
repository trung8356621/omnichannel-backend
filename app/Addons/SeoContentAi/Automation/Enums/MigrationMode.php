<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Enums;

enum MigrationMode: string
{
    case Legacy = 'legacy';
    case Shadow = 'shadow';
    case Action = 'action';

    public static function fromConfig(mixed $value): self
    {
        $raw = is_string($value) ? strtolower(trim($value)) : '';

        return match ($raw) {
            self::Shadow->value => self::Shadow,
            self::Action->value => self::Action,
            default => self::Legacy,
        };
    }

    public function writesViaAction(): bool
    {
        return $this === self::Action;
    }

    public function writesViaLegacy(): bool
    {
        return $this === self::Legacy || $this === self::Shadow;
    }

    public function evaluatesParity(): bool
    {
        return $this === self::Shadow;
    }
}
