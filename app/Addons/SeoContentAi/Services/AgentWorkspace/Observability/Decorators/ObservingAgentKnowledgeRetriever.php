<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Observability\Decorators;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRetriever;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentGroundedContextPackage;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Observability\AgentMetricRecorder;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Observability\AgentTraceService;
use Throwable;

final class ObservingAgentKnowledgeRetriever implements AgentKnowledgeRetriever
{
    public function __construct(
        private readonly AgentKnowledgeRetriever $inner,
        private readonly AgentTraceService $traces,
        private readonly AgentMetricRecorder $metrics,
    ) {}

    public function retrieve(AgentKnowledgeQuery $query): AgentGroundedContextPackage
    {
        $traceId = $this->traces->startTrace(null, 'knowledge_retrieval', [
            'site_id' => $query->siteId,
        ]);
        $spanId = $this->traces->startSpan($traceId, 'knowledge_retrieval');
        $this->metrics->record('knowledge.retrieval', 1, [], $traceId, $query->siteId, $query->ownerUserId);

        try {
            $package = $this->inner->retrieve($query);
            $arr = $package->toArray();
            $itemCount = count($arr['facts'] ?? []) + count($arr['rules'] ?? []) + count($arr['preferences'] ?? []);
            if ($itemCount === 0) {
                $this->metrics->record('knowledge.no_result', 1, [], $traceId, $query->siteId);
            }
            if (! empty($arr['conflicts'] ?? [])) {
                $this->metrics->record('knowledge.conflict', 1, [], $traceId, $query->siteId);
            }
            if (! empty($arr['warnings'] ?? [])) {
                foreach ($arr['warnings'] as $w) {
                    if (is_string($w) && str_starts_with($w, 'stale:')) {
                        $this->metrics->record('knowledge.stale', 1, [], $traceId, $query->siteId);
                        break;
                    }
                }
            }
            $this->traces->endSpan($traceId, $spanId, 'ok', [
                'item_count' => $itemCount,
            ]);
            $this->traces->finishTrace($traceId, 'ok');

            return $package;
        } catch (Throwable $e) {
            $this->traces->endSpan($traceId, $spanId, 'error', [], $e::class);
            $this->traces->finishTrace($traceId, 'error');
            throw $e;
        }
    }
}
