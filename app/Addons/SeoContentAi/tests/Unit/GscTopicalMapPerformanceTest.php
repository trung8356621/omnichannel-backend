<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GscTopicalMapPerformanceTest extends TestCase
{
    public function test_gsc_services_do_not_call_approve_topical_map(): void
    {
        $root = dirname(__DIR__, 2).'/Services/GscIntelligence';
        $files = $this->phpFilesUnder($root);

        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('ApproveTopicalMapCommand', $source, basename($file));
            self::assertStringNotContainsString('ApproveTopicalMapHandler', $source, basename($file));
            self::assertStringNotContainsString('SeoTopicalMapVersion', $source, basename($file));
        }
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
