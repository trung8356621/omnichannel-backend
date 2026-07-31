> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Intelligence (Phase 5)

Addon path: `app/Addons/SeoContentAi/Services/GscIntelligence/`

**Status (code-truth):** Phase 5 foundation **PARTIAL / closable**. Manual import + CommandBus + provider fail-closed + agent/MCP **read catalog** + CommandBus writes for app. Live Google Search Analytics API adapter **out of scope**. Performance Hub GSC Intelligence overlay: Sync CSV preview wired; Overview/Queries/Pages/Opportunities tabs are placeholders (not live fact grids).

GSC Intelligence ingests Search Analytics facts, maps queries/pages to Keyword Intelligence + articles, detects opportunities/cannibalization, reconciles SERP evidence â€” **khÃ´ng** gá»i Google API trá»±c tiáº¿p trong handlers.

## Public refs (`KeywordIntelligencePublicRef`)

| Prefix | Entity |
|--------|--------|
| `gscp_` | Property |
| `gscs_` | Sync run |
| `gscq_` | Query mapping |
| `gscm_` | Page mapping |
| `gsca_` | Performance aggregate |
| `gsco_` | Opportunity |

Numeric ID bá»‹ reject â€” chá»‰ opaque ref.

## CommandBus (`gsc_intelligence.*`)

Commands: `Services/GscIntelligence/Application/Commands/`  
Handlers: `Services/GscIntelligence/Application/Handlers/` â€” **no** `Google\Client` / `Google_Service`.  
Registrar: `ContentProjectCommandBusRegistrar`.  
Write capabilities: `ContentProjectCapabilityRegistry` (full `gsc_intelligence.*` write set).

### Agent Gateway / MCP (code-truth)

| Surface | GSC exposure |
|---------|----------------|
| `ContentProjectAgentGateway::READ_CAPABILITIES` | list/get properties, sync runs, mappings, aggregates, opportunities, operation |
| MCP catalog (`ContentProjectMcpToolCatalog`) | **GSC read tools only** (no GSC write tools listed) |
| Agent `execute` write path | Write caps váº«n **registered** trÃªn CommandBus/registry â†’ cÃ³ thá»ƒ dispatch náº¿u caller gá»i capability name trá»±c tiáº¿p (khÃ´ng pháº£i â€œMCP-only readâ€) |
| Policy scopes | `list_*`/`get_*` â†’ `content-project:read`; other `gsc_intelligence.*` â†’ `content-project:write` |

Read adapter: `Services/GscIntelligence/Agent/GscIntelligenceReadService` (+ Application read helper).

## Core services

| Service | Role |
|---------|------|
| `GscImportPreviewService` | CSV validate + CTR recalc |
| `GscManualImportService` | Preview + dual-write facts |
| `GscDailyMetricPersistService` | Memory + Eloquent upsert REPLACE (`omi_seo_ai`) khi cÃ³ `property_id` |
| `GscSuggestedMappingPersistService` | Sync auto-map persist; skip khi `metadata.manual` |
| `GscProviderResolver` | Fail-closed provider resolution (`config/gsc_intelligence.php`) |
| `GscSyncOperationService` | Staged sync + lock + partial |
| `GscQueryKeywordMapper` | Query â†’ keyword_ref |
| `GscPageArticleMapper` | Page â†’ article_ref |
| `GscOpportunityDetectionService` | Opportunity fingerprints |
| `GscQueryCannibalizationDetector` | Suggestions only |
| `SerpGscEvidenceReconciler` | `serp_gsc_mismatch` review-only |
| `GscKeywordWorkspaceQueryPreviewService` | Preview add queries â†’ KI commands |
| `GscProjectItemPerformanceDeriver` | CP item performance states |

## UI

Performance Hub (`SeoPerformanceHub`) â€” additive overlay `gsc-intelligence-panel` (khÃ´ng thay legacy GSC snapshot tables). Alpine tabs: Overview / Queries / Pages / Opportunities = placeholder copy; Sync = CSV preview (`previewGscImport`). Import commit váº«n qua CommandBus `gsc_intelligence.import_performance` (UI chÆ°a nÃºt commit trong phase nÃ y).

## Docs

- [GSC_PROVIDER_CONTRACT.md](GSC_PROVIDER_CONTRACT.md)
- [GSC_SYNC_OPERATIONS.md](GSC_SYNC_OPERATIONS.md)
- [GSC_DATA_MODEL.md](GSC_DATA_MODEL.md)
- [GSC_QUERY_PAGE_MAPPING.md](GSC_QUERY_PAGE_MAPPING.md)
- [GSC_OPPORTUNITY_ENGINE.md](GSC_OPPORTUNITY_ENGINE.md)
- [GSC_CANNIBALIZATION.md](GSC_CANNIBALIZATION.md)
- [GSC_CONTENT_PROJECT_PERFORMANCE.md](GSC_CONTENT_PROJECT_PERFORMANCE.md)

## Tests

`app/Addons/SeoContentAi/tests/Unit/Gsc*Test.php` â€” pure `PHPUnit\Framework\TestCase`.
