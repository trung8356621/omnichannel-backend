<?php

declare(strict_types=1);

/**
 * Run test discovery audit without depending on Collision's `php artisan test`.
 */

use App\Services\Testing\TestDiscoveryAuditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\NamespaceNotFoundException;

$root = dirname(__DIR__);
require $root.'/scripts/ensure-test-tools.php';

$app = require $root.'/bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$skipList = in_array('--skip-list', $argv, true);
$asJson = in_array('--json', $argv, true);

try {
    $exit = Artisan::call('test:doctor', array_filter([
        '--skip-list' => $skipList ? true : null,
        '--json' => $asJson ? true : null,
    ], static fn (mixed $value): bool => $value !== null));

    echo Artisan::output();
    exit($exit);
} catch (CommandNotFoundException|NamespaceNotFoundException $e) {
    fwrite(STDERR, 'Artisan test:doctor missing — fallback to TestDiscoveryAuditService.'.PHP_EOL);
    fwrite(STDERR, 'Deploy app/Console/Commands/TestDoctorCommand.php + bootstrap/app.php then retry.'.PHP_EOL);
} catch (Throwable $e) {
    if (! str_contains(strtolower($e->getMessage()), 'test')) {
        fwrite(STDERR, $e->getMessage().PHP_EOL);
        exit(1);
    }
    fwrite(STDERR, 'Artisan test:* unavailable ('.$e->getMessage().') — fallback service.'.PHP_EOL);
}

$service = $app->make(TestDiscoveryAuditService::class);
$result = $service->audit([
    'run_phpunit_list' => ! $skipList,
]);

if ($asJson) {
    echo json_encode([
        'ok' => $result->ok(),
        'scanned_test_files' => count($result->scannedTestFiles),
        'discovered_classes' => count($result->discoveredClasses),
        'issues' => array_map(static fn ($issue) => $issue->toArray(), $result->issues),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($result->ok() ? 0 : 1);
}

echo 'Scan *Test.php: '.count($result->scannedTestFiles).PHP_EOL;
echo 'Discovered classes: '.count($result->discoveredClasses).PHP_EOL;
echo 'Issues: '.$result->issueCount().PHP_EOL;

foreach ($result->issues as $issue) {
    echo "[{$issue->code}] {$issue->file}".PHP_EOL;
    echo "  {$issue->message}".PHP_EOL;
    echo "  Fix: {$issue->fix}".PHP_EOL;
}

if ($result->ok()) {
    echo 'OK: test discovery clean.'.PHP_EOL;
    exit(0);
}

fwrite(STDERR, 'test:doctor failed. See docs/operations/TESTING.md'.PHP_EOL);
exit(1);
