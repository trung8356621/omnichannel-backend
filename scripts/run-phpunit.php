<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/scripts/ensure-test-tools.php';

$memory = getenv('TEST_MEMORY_LIMIT') ?: '512M';
$phpBinary = PHP_BINARY;
$phpunit = $root.'/vendor/phpunit/phpunit/phpunit';
$args = array_slice($argv, 1);

// Default to project phpunit.xml when caller did not pass --configuration.
$hasConfiguration = false;
foreach ($args as $arg) {
    if ($arg === '--configuration' || str_starts_with($arg, '--configuration=')) {
        $hasConfiguration = true;
        break;
    }
}

$command = [$phpBinary, '-d', 'memory_limit='.$memory, $phpunit];
if (! $hasConfiguration && is_file($root.'/phpunit.xml')) {
    $command[] = '--configuration';
    $command[] = $root.'/phpunit.xml';
}

array_push($command, ...$args);

$cmd = implode(' ', array_map(static function (string $part): string {
    return escapeshellarg($part);
}, $command));

fwrite(STDOUT, "[run-phpunit] memory_limit={$memory}\n");

passthru($cmd, $exitCode);
exit($exitCode);
