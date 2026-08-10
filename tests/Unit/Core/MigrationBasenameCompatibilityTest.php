<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database\AddonMigrationRegistrar;
use App\Core\Database\MigrationPathLocator;
use PHPUnit\Framework\TestCase;

final class MigrationBasenameCompatibilityTest extends TestCase
{
    public function test_no_duplicate_migration_basenames_across_peer_addons(): void
    {
        $seen = [];
        $dupes = [];

        foreach (MigrationPathLocator::searchRoots(dirname(__DIR__, 3)) as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
                $base = basename($file);
                if (isset($seen[$base])) {
                    $dupes[$base] = [$seen[$base], $file];
                } else {
                    $seen[$base] = $file;
                }
            }
        }

        self::assertSame([], $dupes, 'Duplicate migration basenames break Laravel migration history');
        self::assertGreaterThan(100, count($seen), 'Expected peer migrations present');
    }

    public function test_registrar_does_not_require_seo_content_ai_path(): void
    {
        $configFile = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'addon_migration_ownership.php';
        $all = require $configFile;
        self::assertSame('', (string) ($all['default_legacy_path'] ?? 'missing'));

        $legacy = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Addons'
            .DIRECTORY_SEPARATOR.'SeoContentAi'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $legacyFiles = is_dir($legacy) ? (glob($legacy.DIRECTORY_SEPARATOR.'*.php') ?: []) : [];
        self::assertSame([], $legacyFiles);
    }

    public function test_classify_filename_still_works_without_container(): void
    {
        $registrar = new AddonMigrationRegistrar;
        self::assertSame('content', $registrar->classifyFilename('2026_01_01_create_articles_table.php'));
        self::assertSame('wordpress', $registrar->classifyFilename('2026_01_01_create_wordpress_side_effect.php'));
    }
}
