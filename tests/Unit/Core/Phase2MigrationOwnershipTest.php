<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database\AddonMigrationRegistrar;
use Omnichannel\Addons\WordPress\WpPluginContractMap;
use PHPUnit\Framework\TestCase;

final class Phase2MigrationOwnershipTest extends TestCase
{
    public function test_peer_addon_migration_directories_exist_and_are_non_empty_for_key_owners(): void
    {
        $root = dirname(__DIR__, 3);
        $required = [
            'search-foundation',
            'content',
            'media',
            'publishing',
            'wordpress',
            'search-intelligence',
            'content-projects',
        ];

        foreach ($required as $slug) {
            $dir = $root.DIRECTORY_SEPARATOR.'addons'.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
            $this->assertDirectoryExists($dir, $slug);
            $files = glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [];
            $this->assertNotEmpty($files, "Owner {$slug} must own migrations");
        }
    }

    public function test_legacy_seo_content_ai_migrations_dir_is_empty(): void
    {
        $dir = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Addons'.DIRECTORY_SEPARATOR.'SeoContentAi'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $files = is_dir($dir) ? (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: []) : [];
        $this->assertSame([], $files, 'SeoContentAi must not keep owned migration files');
    }

    public function test_keyword_id_bridge_migration_exists_in_search_intelligence(): void
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'addons'.DIRECTORY_SEPARATOR.'search-intelligence'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_08_10_100000_add_keyword_id_to_seo_keywords_table.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('keyword_id', $src);
    }

    public function test_registrar_classifies_keywords_create_as_search_foundation(): void
    {
        $registrar = new AddonMigrationRegistrar();
        $this->assertSame(
            'search-foundation',
            $registrar->classifyFilename('2026_05_16_100003_create_keywords_table.php'),
        );
        $this->assertSame(
            'publishing',
            $registrar->classifyFilename('2026_08_02_100000_add_publishing_queue_handoff_to_seo_project_tasks.php'),
        );
    }

    public function test_wp_plugin_contract_map_covers_bridge_and_media(): void
    {
        $map = WpPluginContractMap::routeCapabilityMap();
        $this->assertArrayHasKey('POST /posts/{id}/media', $map);
        $this->assertSame('media.library', $map['POST /posts/{id}/media']);
        $this->assertArrayHasKey('Laravel POST /api/seo-wp-bridge/snapshot-callback', $map);
        $this->assertSame('site-sync.v2', $map['Laravel POST /api/seo-wp-bridge/snapshot-callback']);
        $this->assertSame('omi-seo-ai/v1', WpPluginContractMap::PLUGIN_REST_NAMESPACE);
    }
}
