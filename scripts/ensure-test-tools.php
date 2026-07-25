<?php

declare(strict_types=1);

/**
 * Preflight for test tooling (works even when Collision artisan `test` is missing).
 *
 * @return never
 */
function testing_tools_fail(string $message, int $code = 1): void
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($code);
}

$root = dirname(__DIR__);
$phpunit = $root.'/vendor/phpunit/phpunit/phpunit';
$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    testing_tools_fail(
        "vendor/autoload.php missing.\n".
        "Fix: COMPOSER_ALLOW_SUPERUSER=1 composer install\n".
        "Do NOT use --no-dev when you need to run tests."
    );
}

if (! is_file($phpunit)) {
    testing_tools_fail(
        "phpunit not installed (require-dev).\n".
        "Symptom: `php artisan test` → There are no commands defined in the \"test\" namespace.\n".
        "Fix: COMPOSER_ALLOW_SUPERUSER=1 composer install\n".
        "     (omit --no-dev; need phpunit/phpunit + nunomaduro/collision)"
    );
}

require $autoload;

// Ensure Seo addon test PSR-4 exists (Linux case: Tests vs tests/).
$prefixes = require $root.'/vendor/composer/autoload_psr4.php';
$seoTestsPrefix = 'App\\Addons\\SeoContentAi\\Tests\\';
if (! isset($prefixes[$seoTestsPrefix])) {
    testing_tools_fail(
        "PSR-4 map missing for {$seoTestsPrefix}\n".
        "Fix: deploy composer.json then COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload"
    );
}
