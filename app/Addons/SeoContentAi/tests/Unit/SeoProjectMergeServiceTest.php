<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoProjectMergeService;
use PHPUnit\Framework\TestCase;

final class SeoProjectMergeServiceTest extends TestCase
{
    public function test_it_uses_the_first_empty_days_in_the_target_month(): void
    {
        $dates = (new SeoProjectMergeService)->availableDates(
            '2026-06-01',
            ['2026-06-01', '2026-06-03', '2026-06-04'],
            3,
        );

        self::assertSame([
            '2026-06-02',
            '2026-06-05',
            '2026-06-06',
        ], $dates);
    }

    public function test_it_does_not_create_dates_outside_the_target_month(): void
    {
        $occupied = array_map(
            static fn (int $day): string => sprintf('2026-06-%02d', $day),
            range(1, 29),
        );

        $dates = (new SeoProjectMergeService)->availableDates('2026-06-01', $occupied, 3);

        self::assertSame(['2026-06-30'], $dates);
    }
}
