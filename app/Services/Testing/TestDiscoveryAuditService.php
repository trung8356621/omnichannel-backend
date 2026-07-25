<?php

declare(strict_types=1);

namespace App\Services\Testing;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class TestDiscoveryAuditService
{
    private const SUPPORT_DIR_NAMES = ['Support', 'Concerns', 'Fixtures', 'fixtures', 'tmp'];

    /**
     * @param  array{
     *     base_path?: string,
     *     phpunit_xml?: string,
     *     scan_roots?: list<string>,
     *     namespace_map?: array<string, string>,
     *     run_phpunit_list?: bool,
     *     phpunit_binary?: string,
     *     list_timeout?: int
     * }  $options
     */
    public function audit(array $options = []): TestDiscoveryAuditResult
    {
        $basePath = $this->normalizePath($options['base_path'] ?? base_path());
        $phpunitXml = $this->normalizePath($options['phpunit_xml'] ?? $basePath.'/phpunit.xml');
        $runPhpunitList = $options['run_phpunit_list'] ?? true;
        $listTimeout = max(30, (int) ($options['list_timeout'] ?? 180));

        $namespaceMap = $options['namespace_map'] ?? $this->defaultNamespaceMap($basePath);
        $scanRoots = $options['scan_roots'] ?? $this->defaultScanRoots($basePath);
        $configuredDirectories = is_file($phpunitXml)
            ? $this->parseConfiguredDirectories($phpunitXml, $basePath)
            : [];

        $issues = [];
        $scannedTestFiles = [];
        $supportFiles = [];

        if (! is_file($phpunitXml)) {
            $issues[] = new TestDiscoveryIssue(
                file: $phpunitXml,
                code: 'phpunit_xml_missing',
                message: 'Không tìm thấy phpunit.xml.',
                fix: 'Tạo phpunit.xml với testsuites Unit/Feature (và addon suites nếu có).',
            );
        }

        foreach ($scanRoots as $root) {
            $absoluteRoot = $this->absolutePath($basePath, $root);
            if (! is_dir($absoluteRoot)) {
                continue;
            }

            foreach ($this->phpFilesUnder($absoluteRoot) as $absoluteFile) {
                $relative = $this->relativePath($basePath, $absoluteFile);
                $basename = basename($absoluteFile);

                if ($this->isSupportPath($relative, $absoluteFile)) {
                    if (str_ends_with($basename, 'Test.php')) {
                        $issues[] = new TestDiscoveryIssue(
                            file: $relative,
                            code: 'support_named_as_test',
                            message: 'File support/helper mang hậu tố Test.php.',
                            fix: 'Đổi tên bỏ hậu tố Test.php hoặc chuyển sang thư mục Unit/Feature nếu đây là test thật.',
                        );
                    }
                    $supportFiles[] = $relative;

                    continue;
                }

                if (! str_ends_with($basename, 'Test.php')) {
                    if ($this->looksLikeMisnamedTest($basename, (string) file_get_contents($absoluteFile))) {
                        $issues[] = new TestDiscoveryIssue(
                            file: $relative,
                            code: 'wrong_filename_suffix',
                            message: 'File có vẻ là test nhưng không kết thúc bằng Test.php.',
                            fix: 'Đổi tên thành SomethingTest.php (suffix bắt buộc).',
                        );
                    }
                    $supportFiles[] = $relative;

                    continue;
                }

                $scannedTestFiles[] = $relative;
                $issues = [
                    ...$issues,
                    ...$this->validateTestFile(
                        basePath: $basePath,
                        absoluteFile: $absoluteFile,
                        relativeFile: $relative,
                        namespaceMap: $namespaceMap,
                        configuredDirectories: $configuredDirectories,
                    ),
                ];
            }
        }

        $discoveredClasses = [];
        $phpunitListError = null;
        $discoveredByFile = [];

        if ($runPhpunitList && is_file($phpunitXml)) {
            try {
                $list = $this->listPhpunitTests(
                    basePath: $basePath,
                    phpunitXml: $phpunitXml,
                    namespaceMap: $namespaceMap,
                    phpunitBinary: $options['phpunit_binary'] ?? null,
                    timeout: $listTimeout,
                );
                $discoveredClasses = $list['classes'];
                $discoveredByFile = $list['by_file'];
            } catch (Throwable $e) {
                $phpunitListError = $e->getMessage();
                $issues[] = new TestDiscoveryIssue(
                    file: $this->relativePath($basePath, $phpunitXml),
                    code: 'phpunit_list_failed',
                    message: 'Không lấy được danh sách test từ PHPUnit: '.$e->getMessage(),
                    fix: 'Chạy ./vendor/bin/phpunit --list-tests và sửa lỗi bootstrap/autoload trước.',
                );
            }
        }

        if ($discoveredByFile !== []) {
            foreach ($scannedTestFiles as $relative) {
                $absolute = $this->normalizePath($basePath.'/'.$relative);
                $normalizedAbsolute = $this->normalizePath($absolute);
                $count = $discoveredByFile[$normalizedAbsolute]
                    ?? $discoveredByFile[strtolower($normalizedAbsolute)]
                    ?? 0;

                if ($count < 1) {
                    $issues[] = new TestDiscoveryIssue(
                        file: $relative,
                        code: 'not_discovered',
                        message: 'File *Test.php tồn tại nhưng PHPUnit không discover test nào từ file này.',
                        fix: 'Kiểm tra testsuite trong phpunit.xml, namespace/class/autoload, và method test_/#[Test].',
                    );
                }
            }
        }

        $pestAvailable = is_file($basePath.'/vendor/pestphp/pest/bin/pest')
            || class_exists(\Pest\TestSuite::class);

        return new TestDiscoveryAuditResult(
            issues: array_values($issues),
            discoveredClasses: $discoveredClasses,
            scannedTestFiles: $scannedTestFiles,
            supportFiles: $supportFiles,
            configuredDirectories: $configuredDirectories,
            pestAvailable: $pestAvailable,
            phpunitListError: $phpunitListError,
        );
    }

    /**
     * @return list<string>
     */
    public function defaultScanRoots(string $basePath): array
    {
        $roots = ['tests'];

        $addons = $basePath.'/app/Addons';
        if (! is_dir($addons)) {
            return $roots;
        }

        foreach (scandir($addons) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = 'app/Addons/'.$entry.'/tests';
            if (is_dir($basePath.'/'.$candidate)) {
                $roots[] = $candidate;
            }
        }

        return $roots;
    }

    /**
     * @return array<string, string> namespace prefix => absolute directory
     */
    public function defaultNamespaceMap(string $basePath): array
    {
        $map = [
            'Tests\\' => $this->normalizePath($basePath.'/tests'),
        ];

        $addons = $basePath.'/app/Addons';
        if (! is_dir($addons)) {
            return $map;
        }

        foreach (scandir($addons) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $testsDir = $this->normalizePath($basePath.'/app/Addons/'.$entry.'/tests');
            if (is_dir($testsDir)) {
                $map['App\\Addons\\'.$entry.'\\Tests\\'] = $testsDir;
            }
        }

        return $map;
    }

    /**
     * @return list<string> absolute configured directories
     */
    public function parseConfiguredDirectories(string $phpunitXml, string $basePath): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($phpunitXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Không parse được phpunit.xml: '.$phpunitXml);
        }

        $directories = [];
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//testsuites//directory') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $raw = trim($node->textContent ?? '');
            if ($raw === '') {
                continue;
            }
            $absolute = $this->absolutePath($basePath, $raw);
            if (is_dir($absolute)) {
                $directories[] = $this->normalizePath($absolute);
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * @param  array<string, string>  $namespaceMap
     * @param  list<string>  $configuredDirectories
     * @return list<TestDiscoveryIssue>
     */
    public function validateTestFile(
        string $basePath,
        string $absoluteFile,
        string $relativeFile,
        array $namespaceMap,
        array $configuredDirectories,
    ): array {
        $issues = [];
        $code = (string) file_get_contents($absoluteFile);
        if (str_starts_with($code, "\xEF\xBB\xBF")) {
            $code = substr($code, 3);
            $issues[] = new TestDiscoveryIssue(
                file: $relativeFile,
                code: 'utf8_bom',
                message: 'File có UTF-8 BOM — có thể làm hỏng declare(strict_types=1).',
                fix: 'Lưu file UTF-8 without BOM.',
            );
        }

        if ($code === '') {
            $issues[] = new TestDiscoveryIssue(
                file: $relativeFile,
                code: 'empty_file',
                message: 'File test trống.',
                fix: 'Thêm class test hợp lệ hoặc đổi tên nếu đây là helper.',
            );

            return $issues;
        }

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (Throwable $e) {
            $issues[] = new TestDiscoveryIssue(
                file: $relativeFile,
                code: 'syntax_error',
                message: 'Syntax error: '.$e->getMessage(),
                fix: 'Sửa lỗi cú pháp PHP rồi chạy lại test:doctor.',
            );

            return $issues;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $code, $nsMatch)) {
            $issues[] = new TestDiscoveryIssue(
                file: $relativeFile,
                code: 'missing_namespace',
                message: 'Thiếu namespace.',
                fix: 'Thêm namespace khớp đường dẫn theo PSR-4 (vd Tests\\Unit).',
            );

            return $issues;
        }

        $namespace = trim($nsMatch[1]);
        $expectedNamespace = $this->expectedNamespaceForFile($absoluteFile, $namespaceMap);
        if ($expectedNamespace !== null && $namespace !== $expectedNamespace) {
            $issues[] = new TestDiscoveryIssue(
                file: $relativeFile,
                code: 'namespace_mismatch',
                message: "Namespace `{$namespace}` không khớp đường dẫn (kỳ vọng `{$expectedNamespace}`).",
                fix: "Đổi namespace thành `{$expectedNamespace}` hoặc sửa autoload-dev mapping.",
            );
        }

        preg_match_all('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $code, $classMatches);
        $classes = $classMatches[1] ?? [];
        $expectedClass = basename($absoluteFile, '.php');

        $classInfos = $this->declaredClasses($code);
        $classes = array_map(static fn (array $info): string => $info['name'], $classInfos);
        $expectedClass = basename($absoluteFile, '.php');

        if ($classes === []) {
            if ($this->isPestFile($code)) {
                // Pest không dùng class — chỉ báo nếu project không có Pest.
                if (! class_exists(\Pest\TestSuite::class) && ! is_file($basePath.'/vendor/pestphp/pest/bin/pest')) {
                    $issues[] = new TestDiscoveryIssue(
                        file: $relativeFile,
                        code: 'pest_without_framework',
                        message: 'File giống Pest test nhưng Pest chưa được cài.',
                        fix: 'Cài pestphp/pest hoặc chuyển sang PHPUnit class-based test.',
                    );
                }
            } else {
                $issues[] = new TestDiscoveryIssue(
                    file: $relativeFile,
                    code: 'missing_class',
                    message: 'Không tìm thấy class test PHPUnit.',
                    fix: "Khai báo `final class {$expectedClass} extends TestCase`.",
                );
            }
        } else {
            if (count($classes) > 1) {
                $issues[] = new TestDiscoveryIssue(
                    file: $relativeFile,
                    code: 'multiple_classes',
                    message: 'File test chứa nhiều class: '.implode(', ', $classes).'.',
                    fix: 'Giữ một class test duy nhất khớp tên file; tách helper ra tests/Support.',
                );
            }

            $className = $classes[0];
            if ($className !== $expectedClass) {
                $issues[] = new TestDiscoveryIssue(
                    file: $relativeFile,
                    code: 'class_name_mismatch',
                    message: "Class `{$className}` không khớp tên file `{$expectedClass}`.",
                    fix: "Đổi class thành `{$expectedClass}` hoặc đổi tên file cho khớp.",
                );
            }

            if (($classInfos[0]['abstract'] ?? false) === true) {
                $issues[] = new TestDiscoveryIssue(
                    file: $relativeFile,
                    code: 'abstract_test_class',
                    message: 'Class test đang abstract — PHPUnit không chạy abstract class.',
                    fix: 'Đổi thành concrete class hoặc đổi hậu tố file (không dùng *Test.php).',
                );
            }

            if (! $this->extendsTestCase($code)) {
                $issues[] = new TestDiscoveryIssue(
                    file: $relativeFile,
                    code: 'invalid_base_testcase',
                    message: 'Class không extends PHPUnit\\Framework\\TestCase hoặc Tests\\TestCase.',
                    fix: 'use PHPUnit\\Framework\\TestCase (unit thuần) hoặc use Tests\\TestCase (Laravel).',
                );
            }

            if (! $this->hasExecutableTestMethods($code)) {
                $issues[] = new TestDiscoveryIssue(
                    file: $relativeFile,
                    code: 'no_test_methods',
                    message: 'Không có method test (test_* / testX / #[Test]).',
                    fix: 'Thêm public function test_...(): void hoặc #[Test] public function ....',
                );
            }
        }

        if ($configuredDirectories !== [] && ! $this->isInsideConfiguredSuite($absoluteFile, $configuredDirectories)) {
            $issues[] = new TestDiscoveryIssue(
                file: $relativeFile,
                code: 'outside_testsuite',
                message: 'File *Test.php nằm ngoài directory đã khai báo trong phpunit.xml testsuites.',
                fix: 'Thêm <directory suffix="Test.php">...</directory> vào phpunit.xml hoặc chuyển file vào suite hiện có.',
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $namespaceMap
     * @return array{classes: list<string>, by_file: array<string, int>}
     */
    public function listPhpunitTests(
        string $basePath,
        string $phpunitXml,
        array $namespaceMap = [],
        ?string $phpunitBinary = null,
        int $timeout = 180,
    ): array {
        $phpunit = $phpunitBinary ?? $this->resolvePhpunitBinary($basePath);
        $listXml = $this->normalizePath(sys_get_temp_dir().'/phpunit-list-'.bin2hex(random_bytes(8)).'.xml');

        $command = [
            PHP_BINARY,
            $phpunit,
            '--configuration',
            $phpunitXml,
            '--list-tests-xml',
            $listXml,
        ];

        $process = new Process($command, $basePath);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful() && ! is_file($listXml)) {
            $stderr = trim($process->getErrorOutput()."\n".$process->getOutput());
            throw new RuntimeException($stderr !== '' ? $stderr : 'phpunit --list-tests-xml failed (exit '.$process->getExitCode().')');
        }

        if (! is_file($listXml)) {
            throw new RuntimeException('PHPUnit không tạo được file --list-tests-xml.');
        }

        try {
            return $this->parseListTestsXml($listXml, $basePath, $namespaceMap);
        } finally {
            @unlink($listXml);
        }
    }

    /**
     * @param  array<string, string>  $namespaceMap
     * @return array{classes: list<string>, by_file: array<string, int>}
     */
    public function parseListTestsXml(string $listXmlPath, string $basePath, array $namespaceMap = []): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($listXmlPath);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Không parse được list-tests XML.');
        }

        $classes = [];
        $byFile = [];
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('phpunit', 'https://xml.phpunit.de/testSuite');

        $classNodes = $xpath->query('//phpunit:testClass|//testClass|//testCaseClass') ?: [];
        foreach ($classNodes as $classNode) {
            if (! $classNode instanceof DOMElement) {
                continue;
            }
            $className = trim($classNode->getAttribute('name'));
            if ($className === '') {
                continue;
            }
            $classes[] = $className;
            $methodCount = max(
                1,
                $classNode->getElementsByTagName('testMethod')->length
                + $classNode->getElementsByTagName('testCaseMethod')->length
                + $classNode->getElementsByTagNameNS('https://xml.phpunit.de/testSuite', 'testMethod')->length
            );

            $fileAttr = trim($classNode->getAttribute('file'));
            $absolute = $fileAttr !== ''
                ? $this->normalizePath($this->isAbsolutePath($fileAttr) ? $fileAttr : $basePath.'/'.$fileAttr)
                : $this->classNameToFile($className, $basePath, $namespaceMap);

            if ($absolute === null) {
                continue;
            }

            $byFile[$absolute] = ($byFile[$absolute] ?? 0) + $methodCount;
            $byFile[strtolower($absolute)] = $byFile[$absolute];
        }

        // Fallback for flatter XML shapes.
        if ($classes === []) {
            $methodNodes = $xpath->query('//phpunit:testMethod|//testMethod|//testCaseMethod') ?: [];
            foreach ($methodNodes as $methodNode) {
                if (! $methodNode instanceof DOMElement) {
                    continue;
                }
                $className = trim($methodNode->getAttribute('class'));
                if ($className === '') {
                    $id = trim($methodNode->getAttribute('id'));
                    if (str_contains($id, '::')) {
                        $className = explode('::', $id, 2)[0];
                    }
                }
                if ($className === '') {
                    $parent = $methodNode->parentNode;
                    if ($parent instanceof DOMElement) {
                        $className = trim($parent->getAttribute('name'));
                    }
                }
                if ($className === '') {
                    continue;
                }
                $classes[] = $className;
                $absolute = $this->classNameToFile($className, $basePath, $namespaceMap);
                if ($absolute === null) {
                    continue;
                }
                $byFile[$absolute] = ($byFile[$absolute] ?? 0) + 1;
                $byFile[strtolower($absolute)] = $byFile[$absolute];
            }
        }

        if ($classes === []) {
            throw new RuntimeException('PHPUnit list-tests XML không chứa testClass/testMethod nào.');
        }

        return [
            'classes' => array_values(array_unique($classes)),
            'by_file' => $byFile,
        ];
    }

    /**
     * @param  array<string, string>  $namespaceMap
     */
    public function classNameToFile(string $className, string $basePath, array $namespaceMap): ?string
    {
        $className = ltrim($className, '\\');
        $bestPrefix = null;
        $bestDir = null;
        $bestLen = -1;

        foreach ($namespaceMap as $prefix => $directory) {
            $normalizedPrefix = rtrim($prefix, '\\').'\\';
            if (! str_starts_with($className.'\\', $normalizedPrefix) && $className !== rtrim($prefix, '\\')) {
                continue;
            }
            $len = strlen($normalizedPrefix);
            if ($len > $bestLen) {
                $bestLen = $len;
                $bestPrefix = rtrim($prefix, '\\');
                $bestDir = $this->normalizePath($directory);
            }
        }

        if ($bestPrefix !== null && $bestDir !== null) {
            $relative = substr($className, strlen($bestPrefix));
            $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
            $candidate = $this->normalizePath($bestDir.'/'.$relative.'.php');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Generic App\ → app/ fallback (case-sensitive hosts may still miss Tests\ under lowercase tests/).
        if (str_starts_with($className, 'App\\')) {
            $candidate = $this->normalizePath($basePath.'/'.str_replace('\\', '/', $className).'.php');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        if (str_starts_with($className, 'Tests\\')) {
            $candidate = $this->normalizePath($basePath.'/'.str_replace('\\', '/', $className).'.php');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $namespaceMap
     */
    public function expectedNamespaceForFile(string $absoluteFile, array $namespaceMap): ?string
    {
        $absoluteFile = $this->normalizePath($absoluteFile);
        $bestPrefix = null;
        $bestDir = null;
        $bestLen = -1;

        foreach ($namespaceMap as $prefix => $directory) {
            $directory = $this->normalizePath($directory);
            if (! str_starts_with(strtolower($absoluteFile), strtolower($directory.'/'))) {
                // Also allow exact file under directory root.
                if (strtolower(dirname($absoluteFile)) !== strtolower($directory)) {
                    continue;
                }
            }
            $len = strlen($directory);
            if ($len > $bestLen) {
                $bestLen = $len;
                $bestPrefix = $prefix;
                $bestDir = $directory;
            }
        }

        if ($bestPrefix === null || $bestDir === null) {
            return null;
        }

        $relative = substr($absoluteFile, strlen($bestDir));
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        $relativeDir = trim(str_replace('\\', '/', dirname($relative)), '.');
        if ($relativeDir === '' || $relativeDir === '/') {
            return rtrim($bestPrefix, '\\');
        }

        return rtrim($bestPrefix, '\\').'\\'.str_replace('/', '\\', $relativeDir);
    }

    private function resolvePhpunitBinary(string $basePath): string
    {
        $candidates = [
            $basePath.'/vendor/phpunit/phpunit/phpunit',
            $basePath.'/vendor/bin/phpunit',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Không tìm thấy vendor/phpunit/phpunit/phpunit.');
    }

    /**
     * @return \Generator<int, string>
     */
    private function phpFilesUnder(string $absoluteRoot): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                yield $this->normalizePath($file->getPathname());
            }
        }
    }

    /**
     * @return list<array{name: string, abstract: bool}>
     */
    private function declaredClasses(string $code): array
    {
        $tokens = token_get_all($code);
        $classes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            // Skip Foo::class
            $prev = $this->previousMeaningfulToken($tokens, $i);
            if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                continue;
            }

            $abstract = false;
            $scan = $i - 1;
            while ($scan >= 0) {
                $candidate = $tokens[$scan];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $scan--;
                    continue;
                }
                if (is_array($candidate) && in_array($candidate[0], [T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                    if ($candidate[0] === T_ABSTRACT) {
                        $abstract = true;
                    }
                    $scan--;
                    continue;
                }
                break;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    $name = $next[1];
                }
                break;
            }

            // Anonymous class: class without T_STRING name.
            if ($name === null) {
                continue;
            }

            $classes[] = [
                'name' => $name,
                'abstract' => $abstract,
            ];
        }

        return $classes;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function isSupportPath(string $relative, string $absoluteFile): bool
    {
        $parts = explode('/', str_replace('\\', '/', $relative));
        foreach ($parts as $part) {
            if (in_array($part, self::SUPPORT_DIR_NAMES, true)) {
                return true;
            }
        }

        // Root TestCase.php is harness, not a discoverable *Test.php case.
        if (basename($absoluteFile) === 'TestCase.php') {
            return true;
        }

        return false;
    }

    private function looksLikeMisnamedTest(string $basename, string $code): bool
    {
        if (preg_match('/Tests?\.php$/', $basename) !== 1) {
            return false;
        }

        if ($basename === 'TestCase.php') {
            return false;
        }

        return str_contains($code, 'function test')
            || str_contains($code, '#[Test]')
            || (bool) preg_match('/\bit\s*\(/', $code)
            || (bool) preg_match('/\btest\s*\(/', $code);
    }

    private function isPestFile(string $code): bool
    {
        return (bool) preg_match('/\b(?:test|it)\s*\(\s*[\'"]/', $code);
    }

    private function extendsTestCase(string $code): bool
    {
        if (! preg_match('/extends\s+([\\\\\w]+)/', $code, $m)) {
            return false;
        }

        $extends = ltrim($m[1], '\\');
        if (in_array($extends, ['TestCase', 'PHPUnit\\Framework\\TestCase', 'Tests\\TestCase'], true)) {
            return true;
        }

        // Alias: use X as TestCase already covered by extends TestCase.
        return str_ends_with($extends, '\\TestCase');
    }

    private function hasExecutableTestMethods(string $code): bool
    {
        if (preg_match('/public\s+function\s+test[A-Za-z0-9_]*\s*\(/', $code) === 1) {
            return true;
        }

        if (preg_match('/#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\b/', $code) === 1) {
            return true;
        }

        // Legacy @test annotation (discouraged but still discoverable by PHPUnit attributes migration path).
        return preg_match('/@test\b/', $code) === 1;
    }

    /**
     * @param  list<string>  $configuredDirectories
     */
    private function isInsideConfiguredSuite(string $absoluteFile, array $configuredDirectories): bool
    {
        $file = $this->normalizePath($absoluteFile);
        $dir = $this->normalizePath(dirname($file));

        foreach ($configuredDirectories as $configured) {
            $configured = $this->normalizePath($configured);
            if ($dir === $configured || str_starts_with(strtolower($dir), strtolower($configured.'/'))) {
                return true;
            }
        }

        return false;
    }

    private function absolutePath(string $basePath, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($this->isAbsolutePath($path)) {
            return $this->normalizePath($path);
        }

        return $this->normalizePath($basePath.'/'.$path);
    }

    private function relativePath(string $basePath, string $absolute): string
    {
        $base = $this->normalizePath($basePath);
        $absolute = $this->normalizePath($absolute);
        if (str_starts_with(strtolower($absolute), strtolower($base.'/'))) {
            return substr($absolute, strlen($base) + 1);
        }

        return $absolute;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return rtrim($path, '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('#^(?:[A-Za-z]:)?/#', str_replace('\\', '/', $path));
    }
}
