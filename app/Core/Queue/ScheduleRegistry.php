<?php

declare(strict_types=1);

namespace App\Core\Queue;

use Closure;
use InvalidArgumentException;

/**
 * Foundation for addon schedule/queue registration. Core does not own business jobs.
 */
final class ScheduleRegistry
{
    /** @var list<array{addon: string, binder: Closure}> */
    private array $binders = [];

    public function register(string $addonSlug, Closure $binder): void
    {
        if (trim($addonSlug) === '') {
            throw new InvalidArgumentException('Addon slug required for schedule registration.');
        }

        $this->binders[] = ['addon' => $addonSlug, 'binder' => $binder];
    }

    /**
     * @return list<string>
     */
    public function addonSlugs(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): string => $row['addon'],
            $this->binders,
        )));
    }

    /**
     * @param  mixed  $schedule Illuminate Schedule instance when available
     */
    public function bindAll(mixed $schedule): void
    {
        foreach ($this->binders as $row) {
            ($row['binder'])($schedule);
        }
    }
}
