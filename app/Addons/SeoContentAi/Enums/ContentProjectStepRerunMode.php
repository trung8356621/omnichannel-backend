<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum ContentProjectStepRerunMode: string
{
    case SingleStep = 'single_step';
    case StepAndDownstream = 'step_and_downstream';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $raw = trim((string) $value);

        return $raw === '' ? null : self::tryFrom($raw);
    }
}
