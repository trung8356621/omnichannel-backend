<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\SeoDisplayTimezone;
use Carbon\Carbon;
use Tests\TestCase;

final class SeoDisplayTimezoneTest extends TestCase
{
    public function test_format_converts_utc_iso_to_display_timezone(): void
    {
        config(['seo-content-ai.display_timezone' => 'Asia/Ho_Chi_Minh']);

        $formatted = SeoDisplayTimezone::format('2026-07-09T03:28:00+00:00');

        $this->assertSame('09/07/2026 10:28', $formatted);
    }

    public function test_format_schedule_label_uses_display_timezone(): void
    {
        config(['seo-content-ai.display_timezone' => 'Asia/Ho_Chi_Minh']);

        $label = SeoDisplayTimezone::formatScheduleLabel(
            Carbon::parse('2026-07-09T03:28:00Z'),
        );

        $this->assertSame('Th5 9, 2026 at 10:28', $label);
    }
}
