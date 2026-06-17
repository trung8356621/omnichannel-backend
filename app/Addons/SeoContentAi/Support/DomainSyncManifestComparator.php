<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Services\WordPressArticleTimestampService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DomainSyncManifestComparator
{
    /**
     * @param  array<int, array<string, mixed>>  $manifestEntries
     * @param  Collection<int, object{wp_post_id: int, type: string, updated_at: mixed}>  $localArticles
     * @return array{
     *     refs: array<int, array<string, mixed>>,
     *     skipped: int,
     *     new_count: int,
     *     update_count: int
     * }
     */
    public function resolveFetchRefs(array $manifestEntries, Collection $localArticles): array
    {
        $timestampService = new WordPressArticleTimestampService;

        $localIndex = [];
        foreach ($localArticles as $article) {
            $wpId = (int) ($article->wp_post_id ?? 0);
            $type = (string) ($article->type ?? '');
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $localIndex[$this->localKey($type, $wpId)] = $article;
        }

        $refs = [];
        $skipped = 0;
        $newCount = 0;
        $updateCount = 0;

        foreach ($manifestEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $wpId = (int) ($entry['wp_id'] ?? 0);
            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $key = $this->localKey($type, $wpId);
            $local = $localIndex[$key] ?? null;

            if ($this->isTaxonomyType($type)) {
                if ($local === null) {
                    $newCount++;
                    $refs[] = $this->normalizeRef($entry);
                } else {
                    $skipped++;
                }

                continue;
            }

            if ($local === null) {
                $newCount++;
                $refs[] = $this->normalizeRef($entry);

                continue;
            }

            $localUpdated = $local->updated_at instanceof Carbon
                ? $local->updated_at
                : ($local->updated_at !== null ? Carbon::parse((string) $local->updated_at) : null);

            if ($timestampService->remoteIsNewerThanLocal($localUpdated, $entry['post_modified'] ?? null)) {
                $updateCount++;
                $refs[] = $this->normalizeRef($entry);

                continue;
            }

            $skipped++;
        }

        return [
            'refs' => $refs,
            'skipped' => $skipped,
            'new_count' => $newCount,
            'update_count' => $updateCount,
        ];
    }

    private function isTaxonomyType(string $type): bool
    {
        return in_array($type, ['category', 'product_category'], true);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalizeRef(array $entry): array
    {
        return [
            'wp_id' => (int) ($entry['wp_id'] ?? 0),
            'type' => (string) ($entry['type'] ?? ''),
            'wp_post_type' => (string) ($entry['wp_post_type'] ?? ''),
            'wp_entity' => (string) ($entry['wp_entity'] ?? 'post'),
        ];
    }

    private function localKey(string $type, int $wpId): string
    {
        return $type.'|'.$wpId;
    }
}
