<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application;

use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordAnalysisOperation;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordWorkspace;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKiKeyword;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoTopicalMapVersion;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordCannibalizationService;
use RuntimeException;

/**
 * Agent/Filament read surface cho Keyword Intelligence — chỉ trả về public refs,
 * không leak numeric ID nội bộ. Mirror ContentProjectAgentReadService.
 */
final class KeywordIntelligenceReadService
{
    public function __construct(
        private readonly KeywordCannibalizationService $cannibalization,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listWorkspaces(int $siteId, array $input = []): array
    {
        $query = SeoKeywordWorkspace::query()->orderByDesc('id')->limit(100);
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->get()
            ->map(fn (SeoKeywordWorkspace $w): array => $this->serializeWorkspace($w))
            ->all();

        return ['workspaces' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWorkspace(int $siteId, string $workspaceRef): array
    {
        return $this->serializeWorkspace($this->resolveWorkspace($siteId, $workspaceRef), true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listKeywords(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);

        $query = SeoKiKeyword::query()->where('workspace_id', $workspace->id)->orderByDesc('priority_score');

        $clusterRef = trim((string) ($input['cluster_ref'] ?? ''));
        if ($clusterRef !== '') {
            $query->where('cluster_id', KeywordIntelligencePublicRef::resolveClusterIdStrict($clusterRef));
        }

        $reviewStatus = trim((string) ($input['review_status'] ?? ''));
        if ($reviewStatus !== '') {
            $query->where('review_status', $reviewStatus);
        }

        $limit = max(1, min(500, (int) ($input['limit'] ?? 100)));

        $rows = $query->limit($limit)->get()
            ->map(fn (SeoKiKeyword $k): array => $this->serializeKeyword($k))
            ->all();

        return ['workspace_ref' => $workspace->public_ref, 'keywords' => $rows];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listClusters(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);

        $query = SeoKeywordCluster::query()->where('workspace_id', $workspace->id)->orderByDesc('priority_score');

        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $limit = max(1, min(500, (int) ($input['limit'] ?? 200)));

        $rows = $query->limit($limit)->get()
            ->map(fn (SeoKeywordCluster $c): array => $this->serializeCluster($c))
            ->all();

        return ['workspace_ref' => $workspace->public_ref, 'clusters' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTopicalMap(int $siteId, string $workspaceRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);

        $latest = SeoTopicalMapVersion::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('version')
            ->first();

        if (! $latest instanceof SeoTopicalMapVersion) {
            return ['workspace_ref' => $workspace->public_ref, 'map_version' => null];
        }

        return [
            'workspace_ref' => $workspace->public_ref,
            'map_version' => [
                'map_version_ref' => $latest->public_ref,
                'version' => $latest->version,
                'status' => $latest->status,
                'snapshot' => $latest->snapshot,
                'summary' => $latest->summary,
                'generated_at' => $latest->generated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listCannibalization(int $siteId, string $workspaceRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $risks = $this->cannibalization->detect($workspace);

        return ['workspace_ref' => $workspace->public_ref, 'risks' => $risks];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalysisOperation(int $siteId, string $operationRef): array
    {
        $id = KeywordIntelligencePublicRef::resolveOperationIdStrict($operationRef);
        $operation = SeoKeywordAnalysisOperation::query()->find($id);

        if (! $operation instanceof SeoKeywordAnalysisOperation) {
            throw new RuntimeException('Operation không tồn tại.');
        }

        if ($siteId > 0 && (int) $operation->site_id !== $siteId) {
            throw new RuntimeException('Operation không thuộc site hiện tại.');
        }

        return [
            'operation_ref' => $operation->public_ref,
            'workspace_ref' => KeywordIntelligencePublicRef::workspace((int) $operation->workspace_id),
            'status' => $operation->status,
            'stage' => $operation->stage?->value,
            'progress' => $operation->progress,
            'result_code' => $operation->result_code,
            'summary' => $operation->summary,
            'error' => $operation->error,
            'created_at' => $operation->created_at?->toIso8601String(),
        ];
    }

    private function resolveWorkspace(int $siteId, string $workspaceRef): SeoKeywordWorkspace
    {
        $id = KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($workspaceRef);
        $workspace = SeoKeywordWorkspace::query()->find($id);

        if (! $workspace instanceof SeoKeywordWorkspace) {
            throw new RuntimeException('Workspace không tồn tại.');
        }

        if ($siteId > 0 && (int) $workspace->site_id !== $siteId) {
            throw new RuntimeException('Workspace không thuộc site hiện tại.');
        }

        return $workspace;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWorkspace(SeoKeywordWorkspace $workspace, bool $detailed = false): array
    {
        $base = [
            'workspace_ref' => $workspace->public_ref,
            'site_ref' => ContentProjectPublicRef::site((int) $workspace->site_id),
            'name' => $workspace->name,
            'status' => $workspace->status?->value,
            'language' => $workspace->language,
            'country' => $workspace->country,
            'keyword_count' => $workspace->keyword_count,
            'cluster_count' => $workspace->cluster_count,
            'topic_count' => $workspace->topic_count,
            'last_analyzed_at' => $workspace->last_analyzed_at?->toIso8601String(),
            'created_at' => $workspace->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['description'] = $workspace->description;
            $base['clustering_strategy'] = $workspace->clustering_strategy;
            $base['summary'] = $workspace->summary;
            $base['archived_at'] = $workspace->archived_at?->toIso8601String();
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeKeyword(SeoKiKeyword $keyword): array
    {
        return [
            'keyword_ref' => $keyword->public_ref,
            'keyword' => $keyword->keyword,
            'search_intent' => $keyword->search_intent?->value,
            'funnel_stage' => $keyword->funnel_stage?->value,
            'search_volume' => $keyword->search_volume,
            'priority_score' => $keyword->priority_score,
            'analysis_status' => $keyword->analysis_status?->value,
            'review_status' => $keyword->review_status?->value,
            'cluster_ref' => $keyword->cluster_id !== null
                ? KeywordIntelligencePublicRef::cluster((int) $keyword->cluster_id)
                : null,
            'is_primary' => (bool) $keyword->is_primary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCluster(SeoKeywordCluster $cluster): array
    {
        return [
            'cluster_ref' => $cluster->public_ref,
            'name' => $cluster->name,
            'status' => $cluster->status?->value,
            'cluster_type' => $cluster->cluster_type?->value,
            'search_intent' => $cluster->search_intent?->value,
            'keyword_count' => $cluster->keyword_count,
            'total_search_volume' => $cluster->total_search_volume,
            'priority_score' => $cluster->priority_score,
            'suggested_content_type' => $cluster->suggested_content_type,
            'suggested_title' => $cluster->suggested_title,
            'target_article_ref' => $cluster->target_article_ref,
            'content_project_ref' => $cluster->content_project_ref,
            'topic_ref' => $cluster->topic_id !== null
                ? KeywordIntelligencePublicRef::topic((int) $cluster->topic_id)
                : null,
        ];
    }
}
