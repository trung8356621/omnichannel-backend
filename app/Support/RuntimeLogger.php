<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pick log channel by runtime: HTTP → web_app (PHP-FPM writable),
 * CLI/cron/queue → default (existing laravel.log / cron logs).
 *
 * Never fall back from web_app to laravel.log (root-owned on production).
 */
final class RuntimeLogger
{
    public static function channel(): LoggerInterface
    {
        if (app()->runningInConsole()) {
            return Log::channel((string) config('logging.default', 'stack'));
        }

        return Log::channel('web_app');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /**
     * Safe replacement for report($e) on web/editor paths.
     *
     * @param  array<string, mixed>  $context
     */
    public static function report(Throwable $exception, array $context = []): void
    {
        self::error($exception->getMessage(), array_merge([
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], self::requestContext(), $context));
    }

    /**
     * Light request context — no body, tokens, cookies, Authorization.
     *
     * @return array<string, mixed>
     */
    public static function requestContext(): array
    {
        if (app()->runningInConsole()) {
            return [
                'runtime' => 'console',
            ];
        }

        try {
            $request = request();
            $userId = null;
            try {
                $userId = auth()->id();
            } catch (Throwable) {
                $userId = null;
            }

            return [
                'runtime' => 'http',
                'user_id' => $userId,
                'route' => $request->route()?->getName(),
                'path' => '/'.ltrim($request->path(), '/'),
                'method' => $request->method(),
                'request_id' => $request->header('X-Request-ID')
                    ?? $request->header('X-Correlation-Id'),
                'article_id' => self::resolveArticleId($request),
            ];
        } catch (Throwable) {
            return [
                'runtime' => 'http',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        try {
            $merged = array_merge(self::requestContext(), $context);
            self::channel()->log($level, $message, $merged);
        } catch (Throwable) {
            // Never fall back to default laravel.log (may be root-owned).
        }
    }

    private static function resolveArticleId(mixed $request): ?int
    {
        try {
            $route = $request->route();
            if ($route === null) {
                return null;
            }

            $article = $route->parameter('article');
            if (is_object($article) && isset($article->id)) {
                return (int) $article->id;
            }

            if (is_numeric($article)) {
                return (int) $article;
            }

            $raw = $request->input('article_id');
            if (is_numeric($raw)) {
                return (int) $raw;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
