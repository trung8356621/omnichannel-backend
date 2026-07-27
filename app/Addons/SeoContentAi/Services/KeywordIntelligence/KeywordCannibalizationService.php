<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordArticleMappingType;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordArticleMapping;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordWorkspace;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKiKeyword;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;

/**
 * Phát hiện rủi ro cannibalization: nhiều mapping "current_content" cho cùng
 * một keyword, hoặc nhiều bài viết trong cùng một cluster.
 */
final class KeywordCannibalizationService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function detect(SeoKeywordWorkspace $workspace): array
    {
        $threshold = max(2, (int) config('seo-content-ai.keyword_intelligence.cannibalization.multi_mapping_threshold', 2));

        $risks = array_merge(
            $this->detectKeywordLevelRisks($workspace, $threshold),
            $this->detectClusterLevelRisks($workspace, $threshold),
        );

        return $risks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectKeywordLevelRisks(SeoKeywordWorkspace $workspace, int $threshold): array
    {
        $grouped = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
            ->whereNotNull('article_id')
            ->get()
            ->groupBy('keyword_id');

        $risks = [];

        foreach ($grouped as $keywordId => $group) {
            $articleIds = $group->pluck('article_id')->unique()->values();
            if ($articleIds->count() < $threshold) {
                continue;
            }

            $keyword = SeoKiKeyword::query()->find($keywordId);
            if (! $keyword instanceof SeoKiKeyword) {
                continue;
            }

            $riskLevel = $this->riskLevelForCount($articleIds->count());

            $risks[] = [
                'type' => 'keyword_multi_article',
                'keyword_ref' => $keyword->public_ref,
                'keyword' => $keyword->keyword,
                'cluster_ref' => $keyword->cluster?->public_ref,
                'article_refs' => $articleIds
                    ->map(static fn ($id): string => ContentProjectPublicRef::article((int) $id))
                    ->values()
                    ->all(),
                'risk_level' => $riskLevel,
                'recommended_action' => $this->recommendedAction('keyword_multi_article', $riskLevel),
            ];
        }

        return $risks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectClusterLevelRisks(SeoKeywordWorkspace $workspace, int $threshold): array
    {
        $clusters = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->with('keywords:id,cluster_id')
            ->get();

        $risks = [];

        foreach ($clusters as $cluster) {
            $keywordIds = $cluster->keywords->pluck('id');
            if ($keywordIds->isEmpty()) {
                continue;
            }

            $articleIds = SeoKeywordArticleMapping::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('keyword_id', $keywordIds)
                ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
                ->whereNotNull('article_id')
                ->distinct()
                ->pluck('article_id');

            if ($articleIds->count() < $threshold) {
                continue;
            }

            $riskLevel = $this->riskLevelForCount($articleIds->count());

            $risks[] = [
                'type' => 'cluster_multi_article',
                'cluster_ref' => $cluster->public_ref,
                'cluster_name' => $cluster->name,
                'article_refs' => $articleIds
                    ->map(static fn ($id): string => ContentProjectPublicRef::article((int) $id))
                    ->values()
                    ->all(),
                'risk_level' => $riskLevel,
                'recommended_action' => $this->recommendedAction('cluster_multi_article', $riskLevel),
            ];
        }

        return $risks;
    }

    private function riskLevelForCount(int $count): string
    {
        return match (true) {
            $count >= 5 => 'critical',
            $count >= 4 => 'high',
            $count >= 3 => 'medium',
            default => 'low',
        };
    }

    private function recommendedAction(string $type, string $riskLevel): string
    {
        if ($type === 'keyword_multi_article') {
            return match ($riskLevel) {
                'critical' => 'Consolidate all competing articles into one canonical page and 301-redirect the rest.',
                'high' => 'Pick one canonical article for this keyword; de-optimize or merge the others.',
                default => 'Review the articles competing for this keyword and pick a single canonical target.',
            };
        }

        return match ($riskLevel) {
            'critical' => 'Restructure the cluster around one pillar page; merge or redirect overlapping articles.',
            'high' => 'Differentiate targeting between the cluster articles or merge the weakest ones.',
            default => 'Review cluster articles for overlapping intent and consolidate where possible.',
        };
    }
}
