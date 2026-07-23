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
        self::assertStringContainsString("Log::channel('web_app')", $source);
        self::assertStringContainsString('runningInConsole()', $source);
        self::assertStringContainsString('public static function error(', $source);
        self::assertStringContainsString('public static function warning(', $source);
        self::assertStringContainsString('public static function info(', $source);
        self::assertStringContainsString('public static function report(', $source);
        self::assertStringContainsString('Never fall back', $source);
        self::assertStringNotContainsString("Log::channel('single')", $source);
        self::assertStringNotContainsString('laravel.log', $source);
    }

    public function test_request_context_excludes_sensitive_keys(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Support/RuntimeLogger.php');

        self::assertStringContainsString("'user_id'", $source);
        self::assertStringContainsString("'route'", $source);
        self::assertStringContainsString("'article_id'", $source);
        self::assertStringContainsString('X-Request-ID', $source);

        self::assertStringNotContainsString('password', $source);
        self::assertStringNotContainsString('Authorization', $source);
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
        self::assertStringNotContainsString('report($e)', $source);
    }

    public function test_editor_sync_and_lazy_controllers_use_runtime_logger_not_report(): void
    {
        $sync = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Addons/SeoContentAi/Http/Controllers/ArticleEditorSyncController.php',
        );
        $lazy = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Addons/SeoContentAi/Http/Controllers/ArticleEditorLazyPayloadController.php',
        );

        self::assertStringContainsString('RuntimeLogger::report', $sync);
        self::assertStringNotContainsString('report($exception)', $sync);
        self::assertStringContainsString('RuntimeLogger::report', $lazy);
        self::assertStringNotContainsString('report($exception)', $lazy);
    }

    public function test_editor_perf_debug_uses_runtime_logger(): void
    {
        $perf = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Addons/SeoContentAi/Support/ArticleEditorPerfDebug.php',
        );
        $sizer = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Addons/SeoContentAi/Support/ArticleEditorBootstrapSizer.php',
        );

        self::assertStringContainsString('RuntimeLogger::', $perf);
        self::assertStringNotContainsString('Log::', $perf);
        self::assertStringContainsString('RuntimeLogger::', $sizer);
        self::assertStringNotContainsString('Facades\\Log', $sizer);
        self::assertStringNotContainsString('Log::debug', $sizer);
        self::assertStringNotContainsString('Log::warning', $sizer);
    }
}
