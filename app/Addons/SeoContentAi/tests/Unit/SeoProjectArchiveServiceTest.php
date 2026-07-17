<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoProjectArchiveService;
use PHPUnit\Framework\TestCase;

final class SeoProjectArchiveServiceTest extends TestCase
{
    public function test_it_exposes_site_level_archive_api(): void
    {
        $service = new SeoProjectArchiveService;

        self::assertTrue(method_exists($service, 'batchesForProject'));
        self::assertTrue(method_exists($service, 'archiveProject'));
        self::assertTrue(method_exists($service, 'archiveTasks'));
        self::assertTrue(method_exists($service, 'buildGroupedDashboard'));
        self::assertTrue(method_exists($service, 'countForSite'));
        self::assertTrue(method_exists($service, 'unarchiveItem'));
        self::assertFalse(method_exists($service, 'findOrCreateArchiveProject'));
    }
}
