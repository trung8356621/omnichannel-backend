<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Contracts;

/**
 * Đọc Settings workflow bindings — tách cho validator/doctor unit test.
 */
interface SeoCreateArticleSettingsReader
{
    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array;

    public function getPublishArticleTaskId(): ?int;
}
