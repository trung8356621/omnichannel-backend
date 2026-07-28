<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Providers;

use App\Addons\SeoContentAi\Services\GscIntelligence\Contracts\GscIntelligenceProviderInterface;
use App\Addons\SeoContentAi\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use App\Addons\SeoContentAi\Services\GscIntelligence\Data\GscSearchAnalyticsResult;
use App\Addons\SeoContentAi\Services\GscIntelligence\GscFactHashService;

/**
 * Local fake provider cho dev/test — không gọi Google API.
 */
final class FakeLocalGscProvider implements GscIntelligenceProviderInterface
{
    public function __construct(
        private readonly GscFactHashService $factHash,
    ) {}

    public function key(): string
    {
        return 'fake_local';
    }

    public function supports(GscSearchAnalyticsRequest $request): bool
    {
        return $request->providerKey === $this->key();
    }

    public function collectAnalytics(GscSearchAnalyticsRequest $request): GscSearchAnalyticsResult
    {
        $date = $request->startDate !== '' ? $request->startDate : date('Y-m-d');
        $query = 'dịch vụ seo';
        $normalizedQuery = mb_strtolower($query, 'UTF-8');
        $page = 'https://example.test/dich-vu-seo';
        $dataHash = $this->factHash->dataHash(
            $request->propertyRef,
            $date,
            $normalizedQuery,
            $page,
            'vnm',
            'desktop',
            'none',
        );

        $rows = [[
            'date' => $date,
            'query' => $query,
            'normalized_query' => $normalizedQuery,
            'page' => $page,
            'normalized_page' => $page,
            'country' => 'vnm',
            'device' => 'desktop',
            'search_appearance' => 'none',
            'clicks' => 12,
            'impressions' => 240,
            'ctr' => round(12 / 240, 6),
            'position' => 8.4,
            'identity_hash' => $this->factHash->identityHash(
                $request->propertyRef,
                $date,
                $normalizedQuery,
                $page,
                'vnm',
                'desktop',
                'none',
            ),
            'data_hash' => $dataHash,
            'synthetic' => true,
        ]];

        return new GscSearchAnalyticsResult(
            providerKey: $this->key(),
            success: true,
            rows: $rows,
            metadata: ['synthetic' => true, 'row_count' => count($rows)],
            collectedAt: date('c'),
        );
    }

    public function health(): array
    {
        return [
            'healthy' => true,
            'code' => null,
            'message' => null,
            'metadata' => [
                'provider' => $this->key(),
                'synthetic' => true,
                'capabilities' => ['search_analytics'],
            ],
        ];
    }
}
