<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordAnalysisStage;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordAnalysisStatus;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordArticleMappingType;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordAnalysisOperation;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordArticleMapping;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordWorkspace;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKiKeyword;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Throwable;

/**
 * Orchestrate toàn bộ pipeline phân tích workspace: normalize (đã có lúc import)
 * -> classify intent -> map existing content -> cluster -> build topics ->
 * score -> detect cannibalization. Ghi tiến trình vào SeoKeywordAnalysisOperation.
 */
final class KeywordWorkspaceAnalysisService
{
    public function __construct(
        private readonly KeywordIntentClassifier $intentClassifier,
        private readonly KeywordScoringService $scoringService,
        private readonly KeywordClusterService $clusterService,
        private readonly KeywordExistingContentMapper $contentMapper,
        private readonly TopicalMapBuilder $topicalMapBuilder,
        private readonly KeywordCannibalizationService $cannibalizationService,
    ) {}

    /**
     * @return array{operation_id: int, operation_ref: string, summary: array<string, mixed>}
     */
    public function analyze(SeoKeywordWorkspace $workspace, ?string $clusteringStrategy = null): array
    {
        $operation = $this->startOperation($workspace);

        try {
            $workspace->status = KeywordWorkspaceStatus::Analyzing->value;
            $workspace->save();

            $this->advance($operation, KeywordAnalysisStage::ClassifyingIntent, 15);
            $classified = $this->classifyIntents($workspace);

            $this->advance($operation, KeywordAnalysisStage::MappingContent, 35);
            $mapping = $this->contentMapper->mapWorkspace($workspace);

            $this->advance($operation, KeywordAnalysisStage::Scoring, 50);
            $scored = $this->scoreKeywords($workspace);

            $this->advance($operation, KeywordAnalysisStage::Clustering, 65);
            $clusters = $this->clusterService->clusterWorkspace($workspace, $clusteringStrategy ?? (string) config('seo-content-ai.keyword_intelligence.clustering.default_strategy', 'balanced'));

            $this->advance($operation, KeywordAnalysisStage::BuildingTopics, 80);
            $topicalMap = $this->topicalMapBuilder->build($workspace);

            $this->advance($operation, KeywordAnalysisStage::DetectingCannibalization, 92);
            $cannibalization = $this->cannibalizationService->detect($workspace);

            $summary = [
                'classified' => $classified,
                'content_mapping' => $mapping,
                'scored' => $scored,
                'cluster_count' => count($clusters),
                'topical_map' => $topicalMap,
                'cannibalization' => $cannibalization,
            ];

            $operation->status = 'completed';
            $operation->stage = KeywordAnalysisStage::Completed->value;
            $operation->progress = 100;
            $operation->summary = $summary;
            $operation->save();

            $workspace->status = KeywordWorkspaceStatus::Ready->value;
            $workspace->last_analyzed_at = now();
            $workspace->summary = $summary;
            $workspace->save();

            return [
                'operation_id' => (int) $operation->id,
                'operation_ref' => (string) $operation->public_ref,
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            $operation->status = 'failed';
            $operation->stage = KeywordAnalysisStage::Failed->value;
            $operation->error = $e->getMessage();
            $operation->save();

            $workspace->status = KeywordWorkspaceStatus::Draft->value;
            $workspace->save();

            throw $e;
        }
    }

    private function startOperation(SeoKeywordWorkspace $workspace): SeoKeywordAnalysisOperation
    {
        $operation = new SeoKeywordAnalysisOperation([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'tenant_id' => $workspace->tenant_id,
            'site_id' => $workspace->site_id,
            'status' => 'running',
            'stage' => KeywordAnalysisStage::Normalizing->value,
            'progress' => 5,
        ]);
        $operation->save();
        $operation->public_ref = KeywordIntelligencePublicRef::operation((int) $operation->id);
        $operation->save();

        return $operation;
    }

    private function advance(SeoKeywordAnalysisOperation $operation, KeywordAnalysisStage $stage, int $progress): void
    {
        $operation->stage = $stage->value;
        $operation->progress = $progress;
        $operation->save();
    }

    /**
     * @return array{classified: int}
     */
    private function classifyIntents(SeoKeywordWorkspace $workspace): array
    {
        $count = 0;

        SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_excluded', false)
            ->orderBy('id')
            ->chunkById(200, function ($keywords) use (&$count): void {
                foreach ($keywords as $keyword) {
                    $result = $this->intentClassifier->classify((string) $keyword->keyword, (string) $keyword->normalized_keyword);

                    $fieldSources = (array) ($keyword->field_sources ?? []);
                    if (($fieldSources['search_intent'] ?? null) !== 'manual') {
                        $keyword->search_intent = $result['primary']->value;
                        $keyword->secondary_intents = $result['secondary'];
                        $keyword->funnel_stage = $result['funnel']->value;
                    }

                    $keyword->save();
                    $count++;
                }
            });

        return ['classified' => $count];
    }

    /**
     * @return array{scored: int}
     */
    private function scoreKeywords(SeoKeywordWorkspace $workspace): array
    {
        $count = 0;

        $hasCoverage = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
            ->whereNotNull('article_id')
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_excluded', false)
            ->orderBy('id')
            ->chunkById(200, function ($keywords) use (&$count, $hasCoverage): void {
                foreach ($keywords as $keyword) {
                    $intent = $keyword->search_intent instanceof \BackedEnum
                        ? $keyword->search_intent->value
                        : $keyword->search_intent;

                    $scored = $this->scoringService->score([
                        'relevance' => $keyword->relevance_score !== null ? (float) $keyword->relevance_score : null,
                        'business_value' => $keyword->business_value_score !== null ? (float) $keyword->business_value_score : null,
                        'search_volume' => $keyword->search_volume,
                        'keyword_difficulty' => $keyword->keyword_difficulty !== null ? (float) $keyword->keyword_difficulty : null,
                        'competition' => $keyword->competition !== null ? (float) $keyword->competition : null,
                        'has_existing_coverage' => in_array((int) $keyword->id, $hasCoverage, true),
                        'intent' => $intent,
                    ]);

                    $keyword->relevance_score = $scored['relevance_score'];
                    $keyword->opportunity_score = $scored['opportunity_score'];
                    $keyword->total_score = $scored['priority_score'];
                    $keyword->priority_score = $scored['priority_score'];
                    $keyword->intent_score = $scored['confidence'] * 100;
                    $keyword->analysis_status = KeywordAnalysisStatus::Analyzed->value;
                    $keyword->analyzed_at = now();
                    $keyword->metadata = array_merge((array) ($keyword->metadata ?? []), [
                        'score_factors' => $scored['score_factors'],
                        'score_version' => $scored['score_version'],
                    ]);
                    $keyword->save();
                    $count++;
                }
            });

        return ['scored' => $count];
    }
}
