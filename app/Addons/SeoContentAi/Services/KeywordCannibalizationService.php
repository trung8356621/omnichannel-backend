<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

final class KeywordCannibalizationService
{
    public function __construct(
        private readonly SeoPerformanceHubService $performanceHub,
    ) {}

    /**
     * @return list<array{phrase: string, article_count: int, articles: list<array{id: int, title: string, url: string}>}>
     */
    public function detect(?int $siteId, int $limit = 100): array
    {
        return $this->performanceHub->detectCannibalization($siteId, $limit);
    }
}
