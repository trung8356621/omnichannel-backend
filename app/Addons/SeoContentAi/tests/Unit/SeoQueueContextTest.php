<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Support\SeoQueueContext;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use Tests\TestCase;

final class SeoQueueContextTest extends TestCase
{
    public function test_wp_sync_queue_context_bypasses_content_manager_block_without_auth(): void
    {
        Auth::logout();

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod(WordPressArticleSyncService::class, 'blockContentManagerWordPressSync');
        $method->setAccessible(true);

        $blocked = $method->invoke($service);
        $this->assertIsArray($blocked);
        $this->assertFalse($blocked['success'] ?? true);

        SeoQueueContext::runWpSyncFromQueue(function () use ($method, $service): void {
            $this->assertNull($method->invoke($service));
        });
    }
}
