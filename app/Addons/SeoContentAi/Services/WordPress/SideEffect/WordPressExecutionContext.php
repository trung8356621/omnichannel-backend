<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\WordPress\SideEffect;

interface WordPressExecutionContext
{
    public function origin(): string;

    public function correlationId(): string;

    public function articleId(): ?int;

    public function siteId(): ?int;

    public function actorId(): ?int;
}
