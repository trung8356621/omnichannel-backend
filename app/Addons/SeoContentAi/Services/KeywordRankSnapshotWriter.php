<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\DataTransfer\SerpRankResult;
use App\Addons\SeoContentAi\Models\KeywordRankSnapshot;
use App\Addons\SeoContentAi\Models\SeoSerpProviderConnection;

final class KeywordRankSnapshotWriter
{
    public function persist(
        int $siteId,
        int $keywordId,
        SeoSerpProviderConnection $connection,
        SerpRankResult $result,
        ?int $runId = null,
    ): KeywordRankSnapshot {
        $organicPayload = array_map(static fn ($item): array => [
            'position' => $item->position,
            'title' => $item->title,
            'link' => $item->link,
            'displayed_link' => $item->displayedLink,
            'snippet' => $item->snippet,
        ], $result->organicResults);

        return KeywordRankSnapshot::query()->create([
            'site_id' => $siteId,
            'keyword_id' => $keywordId,
            'provider' => $result->provider,
            'connection_id' => (int) $connection->id,
            'location' => $result->location,
            'language' => $result->language,
            'country' => $result->country,
            'device' => $result->device,
            'position' => $result->trackedDomainBestPosition,
            'ranking_url' => $result->trackedUrl,
            'search_volume' => null,
            'allintitle' => null,
            'checked_at' => $result->checkedAt,
            'run_id' => $runId,
            'request_status' => $result->status,
            'duration_ms' => $result->durationMs,
            'error_message' => $result->errorMessage,
            'metadata' => [
                'organic_results' => $organicPayload,
                'result_count' => $result->resultCount,
                'provider_metadata' => $result->metadata,
            ],
        ]);
    }
}
