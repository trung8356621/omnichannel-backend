> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# SERP Intelligence (Phase 4)

Addon path: `app/Addons/SeoContentAi/Services/SerpIntelligence/`

SERP Intelligence thu tháº­p snapshot, phÃ¢n tÃ­ch intent/page type tá»« SERP thá»±c táº¿, validate cluster overlap, phÃ¡t hiá»‡n content gaps â€” **khÃ´ng** ghi Ä‘Ã¨ manual keyword intent.

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

Numeric ID bá»‹ reject â€” chá»‰ opaque ref.

## CommandBus (`serp_intelligence.*`)

Handlers: `Services/SerpIntelligence/Application/Handlers/`

VÃ­ dá»¥: `CollectSerpSnapshotsCommand`, `ImportSerpSnapshotCommand`, `ValidateClusterWithSerpCommand`.

## Core services

| Service | Role |
|---------|------|
| `SerpQueryNormalizationService` | Scope normalize (device mobile â‰  desktop) |
| `SerpProviderResolver` | Fail-closed provider resolution |
| `SerpCollectionOperationService` | Collect + `withCollectionLock` |
| `SerpIntentEvidenceService` | Intent tá»« SERP signals |
| `SerpOverlapService` | URL overlap score only |
| `SerpClusterValidationService` | Suggestions only â€” no DB mutate |
| `SerpContentGapAnalyzer` | Multi-signal gaps |
| `KeywordSerpIntentReconciler` | Manual intent wins |

## UI

Filament `ViewKeywordWorkspace` tab **SERP Intelligence** â€” Alpine sub-tabs (Overview/Queries/Snapshots/â€¦).

## Agent Workspace skills

SERP capabilities qua Agent slash: `/create-serp-queries`, `/import-serp`, `/collect-serp`, `/validate-cluster-serp`, `/list-content-gaps`. `/collect-serp` requires SERP provider configured â€” xem [AGENT_SKILLS.md](AGENT_SKILLS.md) availability `not_configured`.

## Docs

- [SERP_PROVIDER_CONTRACT.md](SERP_PROVIDER_CONTRACT.md)
- [SERP_SNAPSHOT_MODEL.md](SERP_SNAPSHOT_MODEL.md)
- [SERP_INTENT_EVIDENCE.md](SERP_INTENT_EVIDENCE.md)
- [SERP_CLUSTER_VALIDATION.md](SERP_CLUSTER_VALIDATION.md)
- [SERP_CONTENT_GAPS.md](SERP_CONTENT_GAPS.md)
- [SERP_PAGE_FETCH_SECURITY.md](SERP_PAGE_FETCH_SECURITY.md)

## Tests

Pure PHPUnit: `app/Addons/SeoContentAi/tests/Unit/Serp*.php`

## Phase 5 â€” GSC reconciliation (additive)

`SerpGscEvidenceReconciler` (`Services/GscIntelligence/`) emits `serp_gsc_mismatch` suggestions (`review_only`) â€” impression/SERP presence, position delta, intent/page-type mismatch. **KhÃ´ng** auto-rewrite/publish/consolidate. See [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).
