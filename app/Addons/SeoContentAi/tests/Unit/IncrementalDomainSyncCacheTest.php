<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\IncrementalDomainSyncCache;
use PHPUnit\Framework\TestCase;

final class IncrementalDomainSyncCacheTest extends TestCase
{
    public function test_progress_from_state_when_running(): void
    {
        $progress = IncrementalDomainSyncCache::progressFromState([
            'status' => IncrementalDomainSyncCache::STATUS_RUNNING,
            'refs' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
            'offset' => 1,
        ]);

        $this->assertSame(1, $progress['done']);
        $this->assertSame(3, $progress['total']);
        $this->assertTrue($progress['running']);
        $this->assertSame(IncrementalDomainSyncCache::STATUS_RUNNING, $progress['status']);
    }

    public function test_progress_from_state_when_completed(): void
    {
        $progress = IncrementalDomainSyncCache::progressFromState([
            'status' => IncrementalDomainSyncCache::STATUS_COMPLETED,
            'refs' => [
                ['id' => 1],
                ['id' => 2],
            ],
            'offset' => 2,
            'message' => 'Done',
        ]);

        $this->assertSame(2, $progress['done']);
        $this->assertFalse($progress['running']);
        $this->assertSame('Done', $progress['message']);
    }
}
