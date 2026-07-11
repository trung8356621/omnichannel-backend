<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\DataTransfer\SerpRankRequest;
use App\Addons\SeoContentAi\DataTransfer\SerpRankResult;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\KeywordRankCheckRun;
use App\Addons\SeoContentAi\Providers\Serp\SerpRankProviderRegistry;
use App\Addons\SeoContentAi\Services\KeywordRankSnapshotWriter;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Addons\SeoContentAi\Services\SeoSerpProviderConnectionService;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunKeywordRankCheckBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<int>  $keywordIds
     */
    public function __construct(
        public int $runId,
        public int $siteId,
        public int $userId,
        public string $provider,
        public array $keywordIds,
        public ?string $country = null,
        public ?string $location = null,
        public ?string $language = null,
        public ?string $device = null,
    ) {}

    public function handle(
        SeoSerpProviderConnectionService $serpConnections,
        SerpRankProviderRegistry $registry,
        KeywordRankSnapshotWriter $snapshotWriter,
        SeoDatabaseConnectionService $databaseConnection,
    ): void {
        $databaseConnection->bootstrapSeoDatabaseConnection($this->siteId);

        $run = KeywordRankCheckRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        $connection = $serpConnections->resolveForUser($this->userId, $this->provider);
        if ($connection === null || ! $connection->isConfigured()) {
            $this->markRunFailed($run, __('seo-content-ai::filament.api_connections.serp_not_configured'));

            return;
        }

        $provider = $registry->get($this->provider);
        $trackedDomain = $this->resolveTrackedDomain($this->siteId);

        $keywords = Keyword::query()
            ->whereIn('id', $this->keywordIds)
            ->get();

        $processed = 0;
        $failed = 0;

        foreach ($keywords as $keyword) {
            try {
                $request = new SerpRankRequest(
                    keyword: (string) $keyword->phrase,
                    country: $this->country,
                    language: $this->language,
                    location: $this->location,
                    device: $this->device,
                    depth: (int) ($connection->result_depth ?: 100),
                    trackedDomain: $trackedDomain,
                );

                $result = $provider->search($connection, $request);
                $snapshotWriter->persist($this->siteId, (int) $keyword->id, $connection, $result, $this->runId);

                if ($this->isFailureStatus($result->status)) {
                    $failed++;
                } else {
                    $processed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        $run->refresh();
        $run->processed_keywords = (int) $run->processed_keywords + $processed;
        $run->failed_keywords = (int) $run->failed_keywords + $failed;

        if (($run->processed_keywords + $run->failed_keywords) >= (int) $run->total_keywords) {
            $run->status = 'completed';
            $run->completed_at = now();
            $serpConnections->markRankCheckCompleted($connection);
        }

        $run->save();
    }

    public function failed(?Throwable $exception): void
    {
        $run = KeywordRankCheckRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $this->markRunFailed($run, $exception?->getMessage() ?? 'Rank check batch failed.');
    }

    private function markRunFailed(KeywordRankCheckRun $run, string $message): void
    {
        $run->status = 'failed';
        $run->last_error = mb_substr($message, 0, 240);
        $run->completed_at = now();
        $run->save();
    }

    private function resolveTrackedDomain(int $siteId): ?string
    {
        $domain = Site::query()->whereKey($siteId)->value('domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    private function isFailureStatus(string $status): bool
    {
        return ! in_array($status, [
            SerpRankResult::STATUS_SUCCESS_FOUND,
            SerpRankResult::STATUS_SUCCESS_NOT_FOUND,
        ], true);
    }
}
