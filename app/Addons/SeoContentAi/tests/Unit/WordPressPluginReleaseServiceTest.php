<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Exceptions\InvalidWordPressPluginZipException;
use App\Addons\SeoContentAi\Exceptions\WordPressPluginVersionExistsException;
use App\Addons\SeoContentAi\Services\WordPressPluginReleaseService;
use App\Addons\SeoContentAi\Services\WordPressPluginZipInspector;
use App\Models\WpOption;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class WordPressPluginReleaseServiceTest extends TestCase
{
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
                ->where('option_name', WordPressPluginReleaseService::OPTION_KEY)
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

        WpOption::set(WordPressPluginReleaseService::OPTION_KEY, [
            'version' => '1.0.12',
            'slug' => WordPressPluginReleaseService::PLUGIN_SLUG,
            'sections' => ['changelog' => ''],
        ]);

        $service = new WordPressPluginReleaseService;
        $overview = $service->overview();

        $this->assertTrue($overview['has_packages']);
        $this->assertSame('1.0.12', $overview['latest']['version']);
        $this->assertSame(['1.0.11', '1.0.10'], array_column($overview['older'], 'version'));
    }

    public function test_rejects_invalid_version_strings(): void
    {
        $service = new WordPressPluginReleaseService;

        $this->assertTrue($service->isValidVersion('1.0.12'));
        $this->assertFalse($service->isValidVersion('../etc/passwd'));
        $this->assertFalse($service->isValidVersion(''));
    }

    public function test_loads_metadata_from_wp_options(): void
    {
        WpOption::set(WordPressPluginReleaseService::OPTION_KEY, [
            'name' => 'TVH SEO AI Bridge',
            'slug' => WordPressPluginReleaseService::PLUGIN_SLUG,
            'version' => '1.0.30',
            'sections' => ['changelog' => 'test'],
        ]);

        $service = new WordPressPluginReleaseService;
        $metadata = $service->loadMetadata();

        $this->assertSame('1.0.30', $metadata['version']);
    }

    public function test_imports_legacy_info_json_into_wp_options(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plugins/omi-seo-ai-bridge/info.json', json_encode([
            'name' => 'TVH SEO AI Bridge',
            'slug' => WordPressPluginReleaseService::PLUGIN_SLUG,
            'version' => '1.0.29',
            'sections' => ['changelog' => 'legacy'],
        ], JSON_THROW_ON_ERROR));

        $service = new WordPressPluginReleaseService;
        $metadata = $service->loadMetadata();

        $this->assertSame('1.0.29', $metadata['version']);
        $this->assertSame('1.0.29', WpOption::get(WordPressPluginReleaseService::OPTION_KEY)['version']);
    }

    public function test_publish_release_stores_zip_and_metadata(): void
    {
        Storage::fake('public');

        $zipPath = $this->createPluginZip('1.0.31');
        $service = new WordPressPluginReleaseService;

        $result = $service->publishRelease($zipPath, null, 'Fixed sync bug', false);

        $this->assertSame('1.0.31', $result['version']);
        Storage::disk('public')->assertExists('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.31.zip');

        $metadata = WpOption::get(WordPressPluginReleaseService::OPTION_KEY);
        $this->assertSame('1.0.31', $metadata['version']);
        $this->assertStringContainsString('1.0.31: Fixed sync bug', (string) $metadata['sections']['changelog']);

        @unlink($zipPath);
    }

    public function test_publish_release_rejects_duplicate_version(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.31.zip', 'existing');

        $zipPath = $this->createPluginZip('1.0.31');
        $service = new WordPressPluginReleaseService;

        $this->expectException(WordPressPluginVersionExistsException::class);
        $service->publishRelease($zipPath, '1.0.31', 'Duplicate', false);

        @unlink($zipPath);
    }

    public function test_delete_release_removes_older_zip_only(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.30.zip', 'current');
        Storage::disk('public')->put('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.29.zip', 'old');

        WpOption::set(WordPressPluginReleaseService::OPTION_KEY, [
            'version' => '1.0.30',
            'slug' => WordPressPluginReleaseService::PLUGIN_SLUG,
            'sections' => ['changelog' => ''],
        ]);

        $service = new WordPressPluginReleaseService;
        $service->deleteRelease('1.0.29');

        Storage::disk('public')->assertMissing('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.29.zip');
        Storage::disk('public')->assertExists('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.30.zip');
    }

    public function test_delete_release_rejects_current_published_version(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-1.0.30.zip', 'current');

        WpOption::set(WordPressPluginReleaseService::OPTION_KEY, [
            'version' => '1.0.30',
            'slug' => WordPressPluginReleaseService::PLUGIN_SLUG,
            'sections' => ['changelog' => ''],
        ]);

        $service = new WordPressPluginReleaseService;

        $this->expectException(InvalidWordPressPluginZipException::class);
        $service->deleteRelease('1.0.30');
    }

    public function test_zip_inspector_extracts_version_from_main_plugin_file(): void
    {
        $zipPath = $this->createPluginZip('2.0.5');
        $inspector = new WordPressPluginZipInspector;

        $this->assertSame('2.0.5', $inspector->extractVersion($zipPath));

        @unlink($zipPath);
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
