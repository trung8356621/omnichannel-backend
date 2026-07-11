<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Contracts;

use App\Addons\SeoContentAi\DataTransfer\SerpProviderUsage;
use App\Addons\SeoContentAi\DataTransfer\SerpRankRequest;
use App\Addons\SeoContentAi\DataTransfer\SerpRankResult;
use App\Addons\SeoContentAi\Models\SeoSerpProviderConnection;

interface SerpRankProviderInterface
{
    public function providerKey(): string;

    public function displayName(): string;

    /**
     * @return array{ok: bool, message: string, usage: SerpProviderUsage|null}
     */
    public function testConnection(SeoSerpProviderConnection $connection): array;

    public function search(SeoSerpProviderConnection $connection, SerpRankRequest $request): SerpRankResult;

    public function getUsageOrCredits(SeoSerpProviderConnection $connection): ?SerpProviderUsage;
}
