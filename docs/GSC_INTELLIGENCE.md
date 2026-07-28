# GSC Intelligence (Phase 5)

Addon path: `app/Addons/SeoContentAi/Services/GscIntelligence/`

**Status (code-truth):** Phase 5 foundation **PARTIAL / closable**. Manual import + CommandBus + provider fail-closed + agent/MCP **read catalog** + CommandBus writes for app. Live Google Search Analytics API adapter **out of scope**. Performance Hub GSC Intelligence overlay: Sync CSV preview wired; Overview/Queries/Pages/Opportunities tabs are placeholders (not live fact grids).

GSC Intelligence ingests Search Analytics facts, maps queries/pages to Keyword Intelligence + articles, detects opportunities/cannibalization, reconciles SERP evidence — **không** gọi Google API trực tiếp trong handlers.

## Public refs (`KeywordIntelligencePublicRef`)

| Prefix | Entity |
|--------|--------|
| `gscp_` | Property |
| `gscs_` | Sync run |
| `gscq_` | Query mapping |
| `gscm_` | Page mapping |
| `gsca_` | Performance aggregate |
| `gsco_` | Opportunity |

Numeric ID bị reject — chỉ opaque ref.

## CommandBus (`gsc_intelligence.*`)

Commands: `Services/GscIntelligence/Application/Commands/`  
Handlers: `Services/GscIntelligence/Application/Handlers/` — **no** `Google\Client` / `Google_Service`.  
Registrar: `ContentProjectCommandBusRegistrar`.  
Write capabilities: `ContentProjectCapabilityRegistry` (full `gsc_intelligence.*` write set).

### Agent Gateway / MCP (code-truth)

| Surface | GSC exposure |
|---------|----------------|
| `ContentProjectAgentGateway::READ_CAPABILITIES` | list/get properties, sync runs, mappings, aggregates, opportunities, operation |
| MCP catalog (`ContentProjectMcpToolCatalog`) | **GSC read tools only** (no GSC write tools listed) |
| Agent `execute` write path | Write caps vẫn **registered** trên CommandBus/registry → có thể dispatch nếu caller gọi capability name trực tiếp (không phải “MCP-only read”) |
| Policy scopes | `list_*`/`get_*` → `content-project:read`; other `gsc_intelligence.*` → `content-project:write` |

Read adapter: `Services/GscIntelligence/Agent/GscIntelligenceReadService` (+ Application read helper).

## Core services

| Service | Role |
|---------|------|
| `GscImportPreviewService` | CSV validate + CTR recalc |
| `GscManualImportService` | Preview + dual-write facts |
| `GscDailyMetricPersistService` | Memory + Eloquent upsert REPLACE (`omi_seo_ai`) khi có `property_id` |
| `GscSuggestedMappingPersistService` | Sync auto-map persist; skip khi `metadata.manual` |
| `GscProviderResolver` | Fail-closed provider resolution (`config/gsc_intelligence.php`) |
| `GscSyncOperationService` | Staged sync + lock + partial |
| `GscQueryKeywordMapper` | Query → keyword_ref |
| `GscPageArticleMapper` | Page → article_ref |
| `GscOpportunityDetectionService` | Opportunity fingerprints |
| `GscQueryCannibalizationDetector` | Suggestions only |
| `SerpGscEvidenceReconciler` | `serp_gsc_mismatch` review-only |
| `GscKeywordWorkspaceQueryPreviewService` | Preview add queries → KI commands |
| `GscProjectItemPerformanceDeriver` | CP item performance states |

## UI

Performance Hub (`SeoPerformanceHub`) — additive overlay `gsc-intelligence-panel` (không thay legacy GSC snapshot tables). Alpine tabs: Overview / Queries / Pages / Opportunities = placeholder copy; Sync = CSV preview (`previewGscImport`). Import commit vẫn qua CommandBus `gsc_intelligence.import_performance` (UI chưa nút commit trong phase này).

## Docs

- [GSC_PROVIDER_CONTRACT.md](GSC_PROVIDER_CONTRACT.md)
- [GSC_SYNC_OPERATIONS.md](GSC_SYNC_OPERATIONS.md)
- [GSC_DATA_MODEL.md](GSC_DATA_MODEL.md)
- [GSC_QUERY_PAGE_MAPPING.md](GSC_QUERY_PAGE_MAPPING.md)
- [GSC_OPPORTUNITY_ENGINE.md](GSC_OPPORTUNITY_ENGINE.md)
- [GSC_CANNIBALIZATION.md](GSC_CANNIBALIZATION.md)
- [GSC_CONTENT_PROJECT_PERFORMANCE.md](GSC_CONTENT_PROJECT_PERFORMANCE.md)

## Tests

`app/Addons/SeoContentAi/tests/Unit/Gsc*Test.php` — pure `PHPUnit\Framework\TestCase`.
