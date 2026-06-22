<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ExternalPlugin\InvalidWordPressPluginZipException;
use App\Exceptions\ExternalPlugin\WordPressPluginVersionExistsException;
use App\Models\WpOption;
use App\Services\ExternalPlugin\ExternalPluginManifest;
use App\Services\ExternalPlugin\WordPressPluginReleaseService;
use App\Services\ExternalPlugin\WordPressPluginZipInspector;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class WordPressPluginReleaseServiceTest extends TestCase
{
    private function manifest(): ExternalPluginManifest
    {
        return new ExternalPluginManifest(
            slug: 'omi-seo-ai-bridge',
            label: 'TVH SEO AI Bridge',
            platform: 'wordpress',
            packagePrefix: 'omi-seo-ai-bridge',
            metadataOptionKey: 'wp_plugin_bridge_info',
            sourceAddonSlug: 'seo-content-ai',
        );
    }

    private function service(): WordPressPluginReleaseService
    {
        return WordPressPluginReleaseService::forManifest($this->manifest());
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('wp_options')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_02_16_100000_create_wp_options_table.php',
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('wp_options')) {
            WpOption::query()
                ->where('option_name', 'wp_plugin_bridge_info')
                ->delete();
        }

        parent::tearDown();
    }

    public function test_lists_and_sorts_releases_by_version(): void
    {
        Storage::fake('public');

        $dir = 'plugins/omi-seo-ai-bridge';
        Storage::disk('public')->put($dir.'/omi-seo-ai-bridge-1.0.10.zip', 'a');
        Storage::disk('public')->put($dir.'/omi-seo-ai-bridge-1.0.12.zip', 'abc');
        Storage::disk('public')->put($dir.'/omi-seo-ai-bridge-1.0.11.zip', 'ab');

        WpOption::set('wp_plugin_bridge_info', [
            'version' => '1.0.12',
            'slug' => 'omi-seo-ai-bridge',
            'sections' => ['changelog' => ''],
        ]);

        $overview = $this->service()->overview();

        $this->assertTrue($overview['has_packages']);
        $this->assertSame('1.0.12', $overview['latest']['version']);
        $this->assertSame(['1.0.11', '1.0.10'], array_column($overview['older'], 'version'));
    }

    public function test_publish_release_stores_zip_and_metadata(): void
    {
        Storage::fake('public');

        $zipPath = $this->createPluginZip('1.0.31');
        $result = $this->service()->publishRelease($zipPath, null, 'Fixed sync bug', false);

        $this->assertSame('1.0.31', $result['version']);
        Storage::disk('public')->assertExists('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.31.zip');

        $metadata = WpOption::get('wp_plugin_bridge_info');
        $this->assertSame('1.0.31', $metadata['version']);
        $this->assertStringContainsString('1.0.31: Fixed sync bug', (string) $metadata['sections']['changelog']);

        @unlink($zipPath);
    }

    public function test_zip_inspector_extracts_version_from_main_plugin_file(): void
    {
        $zipPath = $this->createPluginZip('2.0.5');
        $inspector = WordPressPluginZipInspector::forManifest($this->manifest());

        $this->assertSame('2.0.5', $inspector->extractVersion($zipPath));

        @unlink($zipPath);
    }

    public function test_publish_release_rejects_duplicate_version(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.31.zip', 'existing');

        $zipPath = $this->createPluginZip('1.0.31');

        $this->expectException(WordPressPluginVersionExistsException::class);
        $this->service()->publishRelease($zipPath, '1.0.31', 'Duplicate', false);

        @unlink($zipPath);
    }

    public function test_delete_release_rejects_current_published_version(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.30.zip', 'current');

        WpOption::set('wp_plugin_bridge_info', [
            'version' => '1.0.30',
            'slug' => 'omi-seo-ai-bridge',
            'sections' => ['changelog' => ''],
        ]);

        $this->expectException(InvalidWordPressPluginZipException::class);
        $this->service()->deleteRelease('1.0.30');
    }

    private function createPluginZip(string $version): string
    {
        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plugin-test-'.uniqid('', true).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'omi-seo-ai-bridge/omi-seo-ai-bridge.php',
            "<?php\n/**\n * Plugin Name: TVH SEO AI Bridge\n * Version: {$version}\n */",
        );
        $zip->close();

        return $zipPath;
    }
}
