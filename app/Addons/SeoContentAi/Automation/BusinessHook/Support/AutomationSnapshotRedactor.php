<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Support;

/**
 * Alias + thin wrapper — snapshot redaction dùng AutomationSnapshotSanitizer.
 * Giữ class cũ để DI binding không gãy.
 */
final class AutomationSnapshotRedactor
{
    public function __construct(
        private readonly AutomationSnapshotSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function redact(?array $payload): ?array
    {
        return $this->sanitizer->sanitize($payload);
    }

    public function redactMessage(?string $message): ?string
    {
        return $this->sanitizer->sanitizeMessage($message);
    }
}
