<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\DataTransfer;

final readonly class SerpProviderUsage
{
    public function __construct(
        public ?int $creditsRemaining = null,
        public ?int $creditsUsed = null,
        public ?string $planLabel = null,
        public bool $available = true,
    ) {}
}
