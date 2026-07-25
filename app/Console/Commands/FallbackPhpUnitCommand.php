<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Fallback when nunomaduro/collision is not installed (--no-dev).
 * Collision normally owns `php artisan test`; without it Symfony reports
 * "There are no commands defined in the test namespace."
 */
final class FallbackPhpUnitCommand extends Command
{
    protected $signature = 'test
        {paths?* : Test file/directory paths or PHPUnit args}
        {--without-tty : Unused (Collision compatibility)}
        {--compact : Unused (Collision compatibility)}
        {--coverage : Unused passthrough hint — use scripts/run-phpunit.php for full PHPUnit flags}';

    protected $description = 'Run PHPUnit (fallback when Collision is not installed)';

    public function handle(): int
    {
        if (class_exists(\NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class)) {
            $this->warn('Collision is installed but its TestCommand was not registered.');
            $this->warn('Try: composer dump-autoload && php artisan package:discover');
        } else {
            $this->warn('nunomaduro/collision missing — using vendor/phpunit directly.');
            $this->warn('Prefer: COMPOSER_ALLOW_SUPERUSER=1 composer install  (without --no-dev)');
        }

        $root = base_path();
        $runner = $root.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'run-phpunit.php';
        if (! is_file($runner)) {
            $this->error('Missing scripts/run-phpunit.php');

            return self::FAILURE;
        }

        $args = [PHP_BINARY, $runner, ...$this->argument('paths')];
        // Forward unknown long options that Laravel did not bind (limited).
        foreach ($_SERVER['argv'] ?? [] as $index => $arg) {
            if ($index === 0) {
                continue;
            }
            if (! is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, '--') && ! in_array($arg, ['--without-tty', '--compact', '--coverage'], true)) {
                if (! in_array($arg, $args, true)) {
                    $args[] = $arg;
                }
            }
        }

        $process = new Process($args, $root);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
