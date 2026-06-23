<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Service;
use App\Services\ExternalPlugin\ExternalPluginManifest;
use App\Services\ExternalPlugin\ExternalPluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExternalPluginRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('services')) {
            $this->markTestSkipped('services table is not available.');
        }
    }

    public function test_resolves_manifest_from_active_service_config(): void
    {
        Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'is_active' => true,
            'config' => [
                'slug' => 'seo-content-ai',
                'external_plugins' => [
                    [
                        'slug' => 'omi-seo-ai-bridge',
                        'label' => 'TVH SEO AI Bridge',
                        'platform' => 'wordpress',
                        'package_prefix' => 'omi-seo-ai-bridge',
                        'metadata_option_key' => 'wp_plugin_bridge_info',
                    ],
                ],
            ],
        ]);

        $registry = new ExternalPluginRegistry;
        $manifest = $registry->resolve('omi-seo-ai-bridge');

        $this->assertInstanceOf(ExternalPluginManifest::class, $manifest);
        $this->assertSame('omi-seo-ai-bridge', $manifest->slug);
        $this->assertSame('seo-content-ai', $manifest->sourceAddonSlug);
        $this->assertSame('wp_plugin_bridge_info', $manifest->metadataOptionKey);
    }

    public function test_ignores_inactive_services(): void
    {
        Service::query()->create([
            'name' => 'Inactive Addon',
            'slug' => 'inactive-addon',
            'addon_namespace' => 'App\\Addons\\Inactive\\Provider',
            'is_active' => false,
            'config' => [
                'external_plugins' => [
                    ['slug' => 'ghost-plugin', 'label' => 'Ghost'],
                ],
            ],
        ]);

        $registry = new ExternalPluginRegistry;

        $this->assertNull($registry->resolve('ghost-plugin'));
    }

    public function test_falls_back_to_addon_json_when_service_config_missing_external_plugins(): void
    {
        Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'is_active' => true,
            'config' => [
                'slug' => 'seo-content-ai',
                'name' => 'SEO Content AI',
            ],
        ]);

        $registry = new ExternalPluginRegistry;
        $manifest = $registry->resolve('omi-seo-ai-bridge');

        $this->assertInstanceOf(ExternalPluginManifest::class, $manifest);
        $this->assertSame('omi-seo-ai-bridge', $manifest->slug);
    }
}
