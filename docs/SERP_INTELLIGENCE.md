# SERP Intelligence (Phase 4)

Addon path: `app/Addons/SeoContentAi/Services/SerpIntelligence/`

SERP Intelligence thu thập snapshot, phân tích intent/page type từ SERP thực tế, validate cluster overlap, phát hiện content gaps — **không** ghi đè manual keyword intent.

## Public refs (`KeywordIntelligencePublicRef`)

| Prefix | Entity |
|--------|--------|
| `srpq_` | Query |
| `srps_` | Snapshot |
| `srpr_` | Result |
| `srpf_` | Feature |
| `srpe_` | Page evidence |
| `srpc_` | Cluster evidence |
| `srpg_` | Content gap |

Numeric ID bị reject — chỉ opaque ref.

## CommandBus (`serp_intelligence.*`)

Handlers: `Services/SerpIntelligence/Application/Handlers/`

Ví dụ: `CollectSerpSnapshotsCommand`, `ImportSerpSnapshotCommand`, `ValidateClusterWithSerpCommand`.

## Core services

| Service | Role |
|---------|------|
| `SerpQueryNormalizationService` | Scope normalize (device mobile ≠ desktop) |
| `SerpProviderResolver` | Fail-closed provider resolution |
| `SerpCollectionOperationService` | Collect + `withCollectionLock` |
| `SerpIntentEvidenceService` | Intent từ SERP signals |
| `SerpOverlapService` | URL overlap score only |
| `SerpClusterValidationService` | Suggestions only — no DB mutate |
| `SerpContentGapAnalyzer` | Multi-signal gaps |
| `KeywordSerpIntentReconciler` | Manual intent wins |

## UI

Filament `ViewKeywordWorkspace` tab **SERP Intelligence** — Alpine sub-tabs (Overview/Queries/Snapshots/…).

## Agent Workspace skills

SERP capabilities qua Agent slash: `/create-serp-queries`, `/import-serp`, `/collect-serp`, `/validate-cluster-serp`, `/list-content-gaps`. `/collect-serp` requires SERP provider configured — xem [AGENT_SKILLS.md](AGENT_SKILLS.md) availability `not_configured`.

## Docs

- [SERP_PROVIDER_CONTRACT.md](SERP_PROVIDER_CONTRACT.md)
- [SERP_SNAPSHOT_MODEL.md](SERP_SNAPSHOT_MODEL.md)
- [SERP_INTENT_EVIDENCE.md](SERP_INTENT_EVIDENCE.md)
- [SERP_CLUSTER_VALIDATION.md](SERP_CLUSTER_VALIDATION.md)
- [SERP_CONTENT_GAPS.md](SERP_CONTENT_GAPS.md)
- [SERP_PAGE_FETCH_SECURITY.md](SERP_PAGE_FETCH_SECURITY.md)

## Tests

Pure PHPUnit: `app/Addons/SeoContentAi/tests/Unit/Serp*.php`

## Phase 5 — GSC reconciliation (additive)

`SerpGscEvidenceReconciler` (`Services/GscIntelligence/`) emits `serp_gsc_mismatch` suggestions (`review_only`) — impression/SERP presence, position delta, intent/page-type mismatch. **Không** auto-rewrite/publish/consolidate. See [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).
