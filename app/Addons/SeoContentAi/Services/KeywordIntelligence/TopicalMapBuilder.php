<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordTopicClusterRelationship;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordTopicStatus;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordTopicType;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordCluster;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKeywordWorkspace;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoKiTopic;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoTopicalMapVersion;
use App\Addons\SeoContentAi\Models\KeywordIntelligence\SeoTopicClusterLink;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Xây topical map tối giản: 1 root theo workspace + pillar theo search_intent
 * bucket, gắn cluster vào pillar qua SeoTopicClusterLink (relationship=primary),
 * và snapshot compact vào SeoTopicalMapVersion. max_depth chỉ giới hạn số tầng
 * pillar (Phase 1 chưa cần subtopic sâu hơn).
 */
final class TopicalMapBuilder
{
    public function build(SeoKeywordWorkspace $workspace, ?int $actorId = null): SeoTopicalMapVersion
    {
        $maxDepth = max(1, (int) config('seo-content-ai.keyword_intelligence.topical_map.max_depth', 3));

        return DB::connection('omi_seo_ai')->transaction(function () use ($workspace, $actorId, $maxDepth): SeoTopicalMapVersion {
            $root = $this->upsertRoot($workspace);

            $clusters = SeoKeywordCluster::query()->where('workspace_id', $workspace->id)->get();

            $grouped = $clusters->groupBy(
                static fn (SeoKeywordCluster $c): string => (string) ($c->search_intent?->value ?? $c->search_intent ?? 'unknown'),
            );

            $pillars = [];
            $totalVolume = 0;
            $pillarDepth = min(1, $maxDepth);

            foreach ($grouped as $intentKey => $group) {
                $pillar = $this->upsertPillar($workspace, $root, (string) $intentKey, $pillarDepth, $group);
                $pillars[] = $pillar;
                $totalVolume += (int) $group->sum('total_search_volume');

                foreach ($group as $cluster) {
                    $cluster->topic_id = $pillar->id;
                    $cluster->save();

                    $this->linkClusterToPillar($pillar, $cluster);
                }
            }

            $workspace->topic_count = SeoKiTopic::query()->where('workspace_id', $workspace->id)->count();
            $workspace->save();

            return $this->persistVersion($workspace, $root, $pillars, $clusters->count(), $totalVolume, $actorId);
        });
    }

    private function upsertRoot(SeoKeywordWorkspace $workspace): SeoKiTopic
    {
        $root = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('parent_id')
            ->where('topic_type', KeywordTopicType::Root->value)
            ->first();

        if (! $root instanceof SeoKiTopic) {
            $root = new SeoKiTopic([
                'public_ref' => 'pending',
                'workspace_id' => $workspace->id,
                'parent_id' => null,
                'topic_type' => KeywordTopicType::Root->value,
            ]);
        }

        $root->fill([
            'name' => (string) $workspace->name,
            'slug' => Str::slug((string) $workspace->name) ?: 'root-'.$workspace->id,
            'status' => KeywordTopicStatus::Draft->value,
            'depth' => 0,
            'path' => (string) $workspace->id,
        ]);
        $root->save();

        if ($root->public_ref === 'pending') {
            $root->public_ref = KeywordIntelligencePublicRef::topic((int) $root->id);
            $root->save();
        }

        return $root;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoKeywordCluster>  $group
     */
    private function upsertPillar(
        SeoKeywordWorkspace $workspace,
        SeoKiTopic $root,
        string $intentKey,
        int $depth,
        $group,
    ): SeoKiTopic {
        $slug = 'pillar-'.Str::slug($intentKey !== '' ? $intentKey : 'unknown');

        $pillar = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->where('parent_id', $root->id)
            ->where('slug', $slug)
            ->first();

        if (! $pillar instanceof SeoKiTopic) {
            $pillar = new SeoKiTopic([
                'public_ref' => 'pending',
                'workspace_id' => $workspace->id,
                'parent_id' => $root->id,
            ]);
        }

        $pillar->fill([
            'name' => $this->pillarName($intentKey),
            'slug' => $slug,
            'topic_type' => KeywordTopicType::Pillar->value,
            'status' => KeywordTopicStatus::Draft->value,
            'depth' => $depth,
            'path' => $root->id.'/'.$slug,
            'keyword_count' => (int) $group->sum('keyword_count'),
            'cluster_count' => $group->count(),
            'total_search_volume' => (int) $group->sum('total_search_volume'),
        ]);
        $pillar->save();

        if ($pillar->public_ref === 'pending') {
            $pillar->public_ref = KeywordIntelligencePublicRef::topic((int) $pillar->id);
            $pillar->save();
        }

        return $pillar;
    }

    private function linkClusterToPillar(SeoKiTopic $pillar, SeoKeywordCluster $cluster): void
    {
        $link = SeoTopicClusterLink::query()
            ->where('topic_id', $pillar->id)
            ->where('cluster_id', $cluster->id)
            ->first();

        if ($link instanceof SeoTopicClusterLink) {
            return;
        }

        $link = new SeoTopicClusterLink([
            'public_ref' => 'pending',
            'topic_id' => $pillar->id,
            'cluster_id' => $cluster->id,
            'relationship' => KeywordTopicClusterRelationship::Primary->value,
            'position' => 0,
        ]);
        $link->save();
        $link->public_ref = KeywordIntelligencePublicRef::topicClusterLink((int) $link->id);
        $link->save();
    }

    /**
     * @param  list<SeoKiTopic>  $pillars
     */
    private function persistVersion(
        SeoKeywordWorkspace $workspace,
        SeoKiTopic $root,
        array $pillars,
        int $clusterCount,
        int $totalVolume,
        ?int $actorId,
    ): SeoTopicalMapVersion {
        $version = (int) (SeoTopicalMapVersion::query()->where('workspace_id', $workspace->id)->max('version') ?? 0) + 1;

        $snapshot = [
            'root' => [
                'topic_ref' => $root->public_ref,
                'name' => $root->name,
            ],
            'pillars' => array_map(static fn (SeoKiTopic $p): array => [
                'topic_ref' => $p->public_ref,
                'name' => $p->name,
                'keyword_count' => $p->keyword_count,
                'cluster_count' => $p->cluster_count,
                'total_search_volume' => $p->total_search_volume,
            ], $pillars),
        ];

        $mapVersion = new SeoTopicalMapVersion([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'version' => $version,
            'status' => 'draft',
            'snapshot' => $snapshot,
            'summary' => [
                'pillar_count' => count($pillars),
                'cluster_count' => $clusterCount,
                'total_search_volume' => $totalVolume,
            ],
            'generated_by' => $actorId,
            'generated_at' => now(),
        ]);
        $mapVersion->save();
        $mapVersion->public_ref = KeywordIntelligencePublicRef::mapVersion((int) $mapVersion->id);
        $mapVersion->save();

        return $mapVersion;
    }

    private function pillarName(string $intentKey): string
    {
        $label = str_replace('_', ' ', $intentKey !== '' ? $intentKey : 'unknown');

        return Str::ucfirst($label).' Intent';
    }
}
