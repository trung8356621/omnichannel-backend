<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\DataTransfer;

final readonly class SerpRankRequest
{
    public function __construct(
        public string $keyword,
        public ?string $country = null,
        public ?string $language = null,
        public ?string $location = null,
        public ?string $device = null,
        public int $depth = 100,
        public ?string $trackedDomain = null,
    ) {}
}
