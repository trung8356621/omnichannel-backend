<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\RunKeywordRankCheckBatchJob;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\KeywordRankCheckRun;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SerpProviderKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KeywordRankCheckService
{
    private const CHUNK_SIZE = 25;

    public function __construct(
        private readonly SeoSerpProviderConnectionService $serpConnections,
    ) {}

    /**
     * @return array{queued: bool, keyword_count: int, run_id: int|null}
     */
    public function dispatchForSite(
        int $siteId,
        int $userId,
        string $provider,
        ?string $country = null,
        ?string $location = null,
        ?string $language = null,
        ?string $device = null,
    ): array {
        if (! SeoAccessControl::canAccessSite($siteId)) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.no_domain'));
        }

        if (! SerpProviderKeys::isValid($provider)) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.invalid_rank_provider'));
        }

        $connection = $this->serpConnections->resolveForUser($userId, $provider);
        if ($connection === null || ! $connection->isConfigured()) {
            throw new \RuntimeException(__('seo-content-ai::filament.api_connections.serp_not_configured'));
        }

        if ($this->hasActiveRun($siteId, $provider)) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.rank_check_already_running'));
        }

        $keywordIds = Keyword::query()
            ->forSite($siteId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($keywordIds === []) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.rank_check_no_keywords'));
        }

        $run = DB::connection('omi_seo_ai')->transaction(function () use (
            $siteId,
            $userId,
            $keywordIds,
            $provider,
            $connection,
            $country,
            $location,
            $language,
            $device,
        ): KeywordRankCheckRun {
            return KeywordRankCheckRun::query()->create([
                'site_id' => $siteId,
                'user_id' => $userId,
                'status' => 'pending',
                'run_type' => 'batch',
                'total_keywords' => count($keywordIds),
                'processed_keywords' => 0,
                'failed_keywords' => 0,
                'provider' => $provider,
                'connection_id' => (int) $connection->id,
                'country' => $country,
                'location' => $location,
                'language' => $language,
                'device' => $device,
                'started_at' => now(),
            ]);
        });

        foreach (array_chunk($keywordIds, self::CHUNK_SIZE) as $chunk) {
            RunKeywordRankCheckBatchJob::dispatch(
                runId: (int) $run->id,
                siteId: $siteId,
                userId: $userId,
                provider: $provider,
                keywordIds: $chunk,
                country: $country,
                location: $location,
                language: $language,
                device: $device,
            )->onQueue('seo');
        }

        $run->status = 'running';
        $run->save();

        return [
            'queued' => true,
            'keyword_count' => count($keywordIds),
            'run_id' => (int) $run->id,
        ];
    }

    public function hasActiveRun(int $siteId, string $provider): bool
    {
        return KeywordRankCheckRun::query()
            ->where('site_id', $siteId)
            ->where('provider', $provider)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }
}
