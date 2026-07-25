<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Testing\TestDiscoveryAuditService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TestDiscoveryAuditServiceTest extends TestCase
{
    private string $fixtureRoot;

    private TestDiscoveryAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestDiscoveryAuditService;
        $this->fixtureRoot = sys_get_temp_dir().'/test-doctor-'.bin2hex(random_bytes(6));
        $this->makeTree($this->fixtureRoot, [
            'tests/Unit' => true,
            'tests/Feature' => true,
            'tests/Support' => true,
            'tests/Outside' => true,
            'vendor/phpunit/phpunit' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_detects_namespace_class_filename_empty_methods_and_outside_suite(): void
    {
        $this->write($this->fixtureRoot.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">tests/Feature</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

        $this->write($this->fixtureRoot.'/tests/Unit/ValidSampleTest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class ValidSampleTest extends TestCase
{
    public function test_ok(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        $this->write($this->fixtureRoot.'/tests/Unit/WrongNamespaceTest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
final class WrongNamespaceTest extends TestCase
{
    public function test_ok(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        $this->write($this->fixtureRoot.'/tests/Unit/ClassMismatchTest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class TotallyWrongName extends TestCase
{
    public function test_ok(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        $this->write($this->fixtureRoot.'/tests/Unit/EmptyMethodsTest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class EmptyMethodsTest extends TestCase
{
    public function helperOnly(): void
    {
    }
}
PHP);

        $this->write($this->fixtureRoot.'/tests/Outside/OrphanTest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Outside;
use PHPUnit\Framework\TestCase;
final class OrphanTest extends TestCase
{
    public function test_ok(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        $this->write($this->fixtureRoot.'/tests/Support/Helper.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Support;
final class Helper
{
    public static function value(): int
    {
        return 1;
    }
}
PHP);

        $this->write($this->fixtureRoot.'/tests/Unit/BadSuffixTests.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class BadSuffixTests extends TestCase
{
    public function test_ok(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        $result = $this->service->audit([
            'base_path' => $this->fixtureRoot,
            'phpunit_xml' => $this->fixtureRoot.'/phpunit.xml',
            'scan_roots' => ['tests'],
            'namespace_map' => [
                'Tests\\' => $this->fixtureRoot.'/tests',
            ],
            'run_phpunit_list' => false,
        ]);

        $codes = array_map(static fn ($i) => $i->code, $result->issues);

        $this->assertContains('namespace_mismatch', $codes);
        $this->assertContains('class_name_mismatch', $codes);
        $this->assertContains('no_test_methods', $codes);
        $this->assertContains('outside_testsuite', $codes);
        $this->assertContains('wrong_filename_suffix', $codes);

        $supportJoined = implode("\n", $result->supportFiles);
        $this->assertStringContainsString('Support/Helper.php', str_replace('\\', '/', $supportJoined));

        // Valid file should not produce convention issues when list step is skipped.
        $validIssues = array_values(array_filter(
            $result->issues,
            static fn ($i) => str_contains($i->file, 'ValidSampleTest.php')
        ));
        $this->assertSame([], $validIssues);

        $this->assertFalse($result->ok());
    }

    public function test_clean_fixture_suite_passes_without_phpunit_list(): void
    {
        $this->write($this->fixtureRoot.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

        $this->write($this->fixtureRoot.'/tests/Unit/CleanOkTest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class CleanOkTest extends TestCase
{
    public function test_ok(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        $this->write($this->fixtureRoot.'/tests/Support/Helper.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Support;
final class Helper
{
}
PHP);

        $result = $this->service->audit([
            'base_path' => $this->fixtureRoot,
            'phpunit_xml' => $this->fixtureRoot.'/phpunit.xml',
            'scan_roots' => ['tests'],
            'namespace_map' => [
                'Tests\\' => $this->fixtureRoot.'/tests',
            ],
            'run_phpunit_list' => false,
        ]);

        $this->assertSame([], $result->issues, json_encode(array_map(
            static fn ($i) => $i->toArray(),
            $result->issues
        )));
        $this->assertTrue($result->ok());
    }

    public function test_support_named_as_test_is_flagged(): void
    {
        $this->write($this->fixtureRoot.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

        $this->write($this->fixtureRoot.'/tests/Support/FakeHelperTest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Support;
final class FakeHelperTest
{
}
PHP);

        $result = $this->service->audit([
            'base_path' => $this->fixtureRoot,
            'phpunit_xml' => $this->fixtureRoot.'/phpunit.xml',
            'scan_roots' => ['tests'],
            'namespace_map' => [
                'Tests\\' => $this->fixtureRoot.'/tests',
            ],
            'run_phpunit_list' => false,
        ]);

        $codes = array_map(static fn ($i) => $i->code, $result->issues);
        $this->assertContains('support_named_as_test', $codes);
        $this->assertFalse($result->ok());
    }

    public function test_expected_namespace_maps_addon_tests_folder_case(): void
    {
        $addonTests = $this->fixtureRoot.'/app/Addons/SeoContentAi/tests/Unit';
        $this->makeTree($this->fixtureRoot, ['app/Addons/SeoContentAi/tests/Unit' => true]);
        $file = $addonTests.'/AddonSampleTest.php';
        $this->write($file, '<?php');

        $expected = $this->service->expectedNamespaceForFile($file, [
            'App\\Addons\\SeoContentAi\\Tests\\' => $this->fixtureRoot.'/app/Addons/SeoContentAi/tests',
        ]);

        $this->assertSame('App\\Addons\\SeoContentAi\\Tests\\Unit', $expected);
    }

    /**
     * @param  array<string, bool>  $dirs
     */
    private function makeTree(string $root, array $dirs): void
    {
        foreach ($dirs as $relative => $_) {
            $path = $root.'/'.$relative;
            if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
                $this->fail('Cannot create fixture dir: '.$path);
            }
        }
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->fail('Cannot create dir for: '.$path);
        }
        file_put_contents($path, $contents);
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($root);
    }
}
