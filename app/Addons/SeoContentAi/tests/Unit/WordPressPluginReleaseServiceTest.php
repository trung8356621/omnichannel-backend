<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\WordPressPluginReleaseService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WordPressPluginReleaseServiceTest extends TestCase
{
    public function test_lists_and_sorts_releases_by_version(): void
    {
        Storage::fake('public');

        $dir = 'plugins/omi-seo-ai-bridge';
        Storage::disk('public')->put($dir . '/omi-seo-ai-bridge-1.0.10.zip', 'a');
        Storage::disk('public')->put($dir . '/omi-seo-ai-bridge-1.0.12.zip', 'abc');
        Storage::disk('public')->put($dir . '/omi-seo-ai-bridge-1.0.11.zip', 'ab');

        $service = new WordPressPluginReleaseService();
        $overview = $service->overview();

        $this->assertTrue($overview['has_packages']);
        $this->assertSame('1.0.12', $overview['latest']['version']);
        $this->assertSame(['1.0.11', '1.0.10'], array_column($overview['older'], 'version'));
    }

    public function test_rejects_invalid_version_strings(): void
    {
        $service = new WordPressPluginReleaseService();

        $this->assertTrue($service->isValidVersion('1.0.12'));
        $this->assertFalse($service->isValidVersion('../etc/passwd'));
        $this->assertFalse($service->isValidVersion(''));
    }
}
