<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Lỗi domain của Article Review workflow — controller map sang HTTP status
 * theo errorCode (conflict → 409, forbidden → 403, invalid_transition → 422).
 *
 * Không dùng property/param tên `$code` — trùng {@see \Exception::$code} (non-readonly).
 */
final class ArticleReviewException extends RuntimeException
{
    public const CODE_CONFLICT = 'conflict';

    public const CODE_FORBIDDEN = 'forbidden';

    public const CODE_INVALID_TRANSITION = 'invalid_transition';

    private readonly string $reviewErrorCode;

    private function __construct(string $message, string $reviewErrorCode, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reviewErrorCode = $reviewErrorCode;
    }

    public static function conflict(string $message): self
    {
        return new self($message, self::CODE_CONFLICT);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, self::CODE_FORBIDDEN);
    }

    public static function invalidTransition(string $message): self
    {
        return new self($message, self::CODE_INVALID_TRANSITION);
    }

    public function errorCode(): string
    {
        return $this->reviewErrorCode;
    }

    public function httpStatus(): int
    {
        return match ($this->reviewErrorCode) {
            self::CODE_CONFLICT => 409,
            self::CODE_FORBIDDEN => 403,
            self::CODE_INVALID_TRANSITION => 422,
            default => 422,
        };
    }
}
