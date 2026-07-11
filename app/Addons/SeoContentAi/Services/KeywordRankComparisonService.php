<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\RunSingleKeywordRankCheckJob;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\KeywordRankCheckRun;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\SerpProviderKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KeywordRankComparisonService
{
    public const MAX_KEYWORDS = 5;

    public function __construct(
        private readonly SeoSerpProviderConnectionService $serpConnections,
    ) {}

    /**
     * @param  list<string>  $providers
     * @param  list<int>|null  $keywordIds
     * @return array{queued: bool, batch_id: string, job_count: int}
     */
    public function dispatchComparison(
        int $siteId,
        int $userId,
        array $providers,
        ?array $keywordIds = null,
        ?string $keywordPhrase = null,
        ?string $country = null,
        ?string $location = null,
        ?string $language = null,
        ?string $device = null,
    ): array {
        if (! SeoAccessControl::canAccessSite($siteId)) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.no_domain'));
        }

        $providers = array_values(array_filter(
            $providers,
            static fn (string $provider): bool => SerpProviderKeys::isValid($provider),
        ));

        if ($providers === []) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_no_providers'));
        }

        foreach ($providers as $provider) {
            $connection = $this->serpConnections->resolveForUser($userId, $provider);
            if ($connection === null || ! $connection->isConfigured()) {
                throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_provider_not_configured', [
                    'provider' => SerpProviderKeys::label($provider),
                ]));
            }
        }

        $resolvedKeywordIds = $this->resolveKeywordIds($siteId, $keywordIds, $keywordPhrase);
        if ($resolvedKeywordIds === []) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_no_keywords'));
        }

        if (count($resolvedKeywordIds) > self::MAX_KEYWORDS) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_too_many_keywords', [
                'max' => self::MAX_KEYWORDS,
            ]));
        }

        $batchId = (string) Str::uuid();
        $jobCount = 0;

        DB::connection('omi_seo_ai')->transaction(function () use (
            $siteId,
            $userId,
            $providers,
            $resolvedKeywordIds,
            $batchId,
            $country,
            $location,
            $language,
            $device,
            &$jobCount,
        ): void {
            foreach ($providers as $provider) {
                $connection = $this->serpConnections->resolveForUser($userId, $provider);
                if ($connection === null) {
                    continue;
                }

                $run = KeywordRankCheckRun::query()->create([
                    'site_id' => $siteId,
                    'user_id' => $userId,
                    'status' => 'running',
                    'run_type' => 'comparison',
                    'comparison_batch_id' => $batchId,
                    'total_keywords' => count($resolvedKeywordIds),
                    'processed_keywords' => 0,
                    'failed_keywords' => 0,
                    'provider' => $provider,
                    'connection_id' => (int) $connection->id,
                    'country' => $country,
                    'location' => $location,
                    'language' => $language,
                    'device' => $device,
                    'started_at' => now(),
                    'metadata' => ['providers' => $providers],
                ]);

                foreach ($resolvedKeywordIds as $keywordId) {
                    RunSingleKeywordRankCheckJob::dispatch(
                        runId: (int) $run->id,
                        siteId: $siteId,
                        userId: $userId,
                        provider: $provider,
                        keywordId: (int) $keywordId,
                        country: $country,
                        location: $location,
                        language: $language,
                        device: $device,
                        comparisonBatchId: $batchId,
                    )->onQueue('seo');

                    $jobCount++;
                }
            }
        });

        return [
            'queued' => $jobCount > 0,
            'batch_id' => $batchId,
            'job_count' => $jobCount,
        ];
    }

    /**
     * @param  list<int>|null  $keywordIds
     * @return list<int>
     */
    private function resolveKeywordIds(int $siteId, ?array $keywordIds, ?string $keywordPhrase): array
    {
        if (is_array($keywordIds) && $keywordIds !== []) {
            return Keyword::query()
                ->forSite($siteId)
                ->whereIn('id', $keywordIds)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        $phrase = trim((string) $keywordPhrase);
        if ($phrase === '') {
            return [];
        }

        $keyword = Keyword::query()
            ->forSite($siteId)
            ->where('phrase', $phrase)
            ->first();

        return $keyword !== null ? [(int) $keyword->id] : [];
    }
}
