<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoProjectArchiveService;
use PHPUnit\Framework\TestCase;

final class SeoProjectArchiveServiceTest extends TestCase
{
    public function test_it_exposes_batch_loader_for_project_detail(): void
    {
        $service = new SeoProjectArchiveService;

        self::assertTrue(method_exists($service, 'batchesForProject'));
        self::assertTrue(method_exists($service, 'archiveProject'));
    }
}
