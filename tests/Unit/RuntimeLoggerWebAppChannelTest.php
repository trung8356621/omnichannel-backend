<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static contracts — web_app channel isolation from root-owned laravel.log.
 * Remote-first: no HTTP server, no cron, no include of config (storage_path).
 */
final class RuntimeLoggerWebAppChannelTest extends TestCase
{
    public function test_logging_config_defines_independent_web_app_daily_channel(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/config/logging.php');

        self::assertStringContainsString("'web_app' =>", $source);
        self::assertStringContainsString("'driver' => 'daily'", $source);
        self::assertStringContainsString("storage_path('logs/web-app.log')", $source);
        self::assertStringContainsString("env('WEB_APP_LOG_LEVEL', 'warning')", $source);
        self::assertStringContainsString("env('WEB_APP_LOG_DAYS', 14)", $source);

        // Must not stack web_app onto laravel.log / single / default stack list.
        self::assertDoesNotMatchRegularExpression(
            "/'stack'\\s*=>\\s*\\[[^\\]]*web_app/s",
            $source,
        );
    }

    public function test_default_channel_unchanged_for_cron(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/config/logging.php');

        self::assertStringContainsString("env('LOG_CHANNEL', 'stack')", $source);
        self::assertStringContainsString("storage_path('logs/laravel.log')", $source);
        self::assertStringNotContainsString("env('LOG_CHANNEL', 'web_app')", $source);
    }

    public function test_runtime_logger_helpers_exist_and_never_mention_laravel_log_fallback(): void
    {
        $path = dirname(__DIR__, 2).'/app/Support/RuntimeLogger.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('public static function channel()', $source);
        self::assertStringContainsString("self::safeChannel('web_app'", $source);
        self::assertStringContainsString('runningInConsole()', $source);
        self::assertStringContainsString('public static function error(', $source);
        self::assertStringContainsString('public static function warning(', $source);
        self::assertStringContainsString('public static function info(', $source);
        self::assertStringContainsString('public static function report(', $source);
        self::assertStringContainsString('Never fall back', $source);
        self::assertStringNotContainsString("Log::channel('single')", $source);
        // Docblock/comments may mention laravel.log as the thing we avoid — code path must not Log:: to it.
        self::assertStringNotContainsString("Log::channel('stack')", $source);
        self::assertDoesNotMatchRegularExpression("/Log::channel\\(\\s*'laravel\\.log'\\s*\\)/", $source);
    }

    public function test_request_context_excludes_sensitive_keys(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Support/RuntimeLogger.php');

        self::assertStringContainsString("'user_id'", $source);
        self::assertStringContainsString("'route'", $source);
        self::assertStringContainsString("'article_id'", $source);
        self::assertStringContainsString('X-Request-ID', $source);

        // requestContext must not read secrets from the request.
        self::assertStringNotContainsString("\$request->input('password')", $source);
        self::assertStringNotContainsString("header('Authorization')", $source);
        self::assertStringNotContainsString('bearerToken', $source);
        self::assertStringNotContainsString('getContent(', $source);
        self::assertStringNotContainsString('$_COOKIE', $source);
    }

    public function test_http_exception_handler_routes_to_web_app_and_stops_default(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/bootstrap/app.php');

        self::assertStringContainsString('RuntimeLogger::report', $source);
        self::assertStringContainsString('runningInConsole()', $source);
        self::assertStringContainsString('return false;', $source);
    }

    public function test_app_service_provider_forces_web_app_default_on_http(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Providers/AppServiceProvider.php');

        self::assertStringContainsString("config(['logging.default' => 'web_app'])", $source);
        self::assertStringContainsString('runningInConsole()', $source);
        self::assertStringContainsString('RuntimeLogger::warning', $source);
        self::assertStringContainsString('RuntimeLogger::report', $source);
        self::assertStringNotContainsString('logger()->warning', $source);
        self::assertStringNotContainsString('logger()->info', $source);
        // Forbid bare report($e); allow RuntimeLogger::report($e).
        self::assertDoesNotMatchRegularExpression('/(?<!RuntimeLogger::)report\(\s*\$e\s*\)/', $source);
    }

    public function test_editor_sync_and_lazy_controllers_use_runtime_logger_not_report(): void
    {
        $sync = \Tests\Support\LegacyAddonPath::read('Http/Controllers/ArticleEditorSyncController.php');
        $lazy = \Tests\Support\LegacyAddonPath::read('Http/Controllers/ArticleEditorLazyPayloadController.php');

        self::assertStringContainsString('RuntimeLogger::report', $sync);
        self::assertStringNotContainsString('report($exception)', $sync);
        self::assertStringContainsString('RuntimeLogger::report', $lazy);
        self::assertStringNotContainsString('report($exception)', $lazy);
    }

    public function test_editor_perf_debug_uses_runtime_logger(): void
    {
        $perf = \Tests\Support\LegacyAddonPath::read('Support/ArticleEditorPerfDebug.php');
        $sizer = \Tests\Support\LegacyAddonPath::read('Support/ArticleEditorBootstrapSizer.php');

        self::assertStringContainsString('RuntimeLogger::', $perf);
        self::assertStringNotContainsString('Log::', $perf);
        self::assertStringContainsString('RuntimeLogger::', $sizer);
        self::assertStringNotContainsString('Facades\\Log', $sizer);
        self::assertStringNotContainsString('Log::debug', $sizer);
        self::assertStringNotContainsString('Log::warning', $sizer);
    }
}
