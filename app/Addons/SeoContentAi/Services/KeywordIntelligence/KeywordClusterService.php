<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordClusterStatus;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordClusterType;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordSearchIntent;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKiKeyword;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordWorkspace;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Clustering: intent + entity/modifier buckets — not cosine-only.
 * Strategies: strict | balanced | broad
 */
final class KeywordClusterService
{
    /**
     * @return list<SeoKeywordCluster>
     */
    public function clusterWorkspace(SeoKeywordWorkspace $workspace, string $strategy = 'balanced'): array
    {
        $strategy = in_array($strategy, ['strict', 'balanced', 'broad'], true) ? $strategy : 'balanced';

        $keywords = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_excluded', false)
            ->where('is_duplicate', false)
            ->orderBy('id')
            ->get();

        /** @var array<string, list<SeoKiKeyword>> $buckets */
        $buckets = [];
        foreach ($keywords as $keyword) {
            $key = $this->bucketKey($keyword, $strategy);
            $buckets[$key][] = $keyword;
        }

        $created = [];
        foreach ($buckets as $bucketKeywords) {
            if ($bucketKeywords === []) {
                continue;
            }

            $primary = $this->pickPrimary($bucketKeywords);
            $intent = $primary->search_intent instanceof KeywordSearchIntent
                ? $primary->search_intent
                : KeywordSearchIntent::tryFrom((string) $primary->search_intent);

            $name = (string) $primary->keyword;
            $slug = Str::slug(mb_substr($name, 0, 80));
            if ($slug === '') {
                $slug = 'cluster-'.$primary->id;
            }

            $cluster = new SeoKeywordCluster([
                'public_ref' => 'pending',
                'workspace_id' => $workspace->id,
                'tenant_id' => $workspace->tenant_id,
                'site_id' => $workspace->site_id,
                'name' => $name,
                'slug' => $slug.'-'.$primary->id,
                'primary_keyword_id' => $primary->id,
                'search_intent' => $intent?->value,
                'funnel_stage' => $primary->funnel_stage instanceof \BackedEnum
                    ? $primary->funnel_stage->value
                    : $primary->funnel_stage,
                'cluster_type' => $this->inferClusterType($intent)->value,
                'status' => KeywordClusterStatus::Draft->value,
                'keyword_count' => count($bucketKeywords),
                'relevance_score' => $primary->relevance_score,
                'opportunity_score' => $primary->opportunity_score,
                'priority_score' => $primary->priority_score ?? $primary->total_score,
                'suggested_content_type' => 'write_new',
                'suggested_title' => $primary->keyword,
                'suggested_description' => null,
                'metadata' => [
                    'strategy' => $strategy,
                ],
            ]);
            $cluster->save();
            $cluster->public_ref = KeywordIntelligencePublicRef::cluster((int) $cluster->id);
            $cluster->save();

            $created[] = $cluster;

            foreach ($bucketKeywords as $member) {
                $member->cluster_id = (int) $cluster->id;
                $member->is_primary = (int) $member->id === (int) $primary->id;
                $member->save();
            }
        }

        $workspace->cluster_count = count($created);
        $workspace->save();

        return $created;
    }

    /**
     * @param  list<SeoKiKeyword>|Collection<int, SeoKiKeyword>  $keywords
     */
    private function pickPrimary(array|Collection $keywords): SeoKiKeyword
    {
        $list = $keywords instanceof Collection ? $keywords->all() : $keywords;
        usort($list, static function (SeoKiKeyword $a, SeoKiKeyword $b): int {
            $pa = (float) ($a->priority_score ?? $a->total_score ?? 0);
            $pb = (float) ($b->priority_score ?? $b->total_score ?? 0);
            if ($pa === $pb) {
                return ((int) ($b->search_volume ?? 0)) <=> ((int) ($a->search_volume ?? 0));
            }

            return $pb <=> $pa;
        });

        return $list[0];
    }

    private function bucketKey(SeoKiKeyword $keyword, string $strategy): string
    {
        $intent = (string) ($keyword->search_intent?->value ?? $keyword->search_intent ?? 'unknown');
        $normalized = (string) $keyword->normalized_keyword;
        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        $entity = $tokens[0] ?? $normalized;

        $modifiers = ['tổng', 'tong', 'website', 'dịch', 'dich', 'vụ', 'vu', 'giá', 'gia', 'best', 'top'];
        $core = array_values(array_filter(
            $tokens,
            static fn (string $t): bool => ! in_array($t, $modifiers, true),
        ));
        $coreKey = implode(' ', array_slice($core !== [] ? $core : $tokens, 0, 3));

        return match ($strategy) {
            'strict' => $intent.'|'.$normalized,
            'broad' => $intent.'|'.$entity,
            default => $intent.'|'.$coreKey,
        };
    }

    private function inferClusterType(?KeywordSearchIntent $intent): KeywordClusterType
    {
        return match ($intent) {
            KeywordSearchIntent::Transactional => KeywordClusterType::Transactional,
            KeywordSearchIntent::Commercial => KeywordClusterType::Commercial,
            KeywordSearchIntent::Local => KeywordClusterType::Local,
            KeywordSearchIntent::Informational => KeywordClusterType::Supporting,
            default => KeywordClusterType::Cluster,
        };
    }
}
