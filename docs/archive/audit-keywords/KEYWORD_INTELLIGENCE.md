> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Keyword Intelligence (Phase 1â€“2)

Phase 2 adds analysis engine (normalizeâ†’dedupâ†’intentâ†’scoreâ†’mapâ†’clusterâ†’cannibalization), operation lock, cluster merge/split/move, cannibalization issues table, and Filament tabs Existing Content + Analysis.

See also: [KEYWORD_ANALYSIS_OPERATIONS.md](KEYWORD_ANALYSIS_OPERATIONS.md), [KEYWORD_CLUSTERING.md](KEYWORD_CLUSTERING.md), [KEYWORD_CANNIBALIZATION.md](KEYWORD_CANNIBALIZATION.md).

## Manual override

`field_sources.{field}.source = manual` wins over analysis.

Bá»™ tÃ­nh nÄƒng nghiÃªn cá»©u tá»« khÃ³a cho addon SEO Content AI: import â†’ normalize â†’ classify intent â†’ score â†’ map ná»™i dung hiá»‡n cÃ³ â†’ cluster â†’ xÃ¢y topical map â†’ phÃ¡t hiá»‡n cannibalization â†’ convert cluster Ä‘Ã£ duyá»‡t thÃ nh Content Project.

KhÃ´ng thuá»™c `ContentProject` aggregate â€” dÃ¹ng chung `ContentProjectCommandBus` / `ActorContext` / `ContentProjectActionResult` nhÆ°ng cÃ³ `KeywordIntelligencePublicRef` riÃªng (prefix `kww_`, `kw_`, `kwc_`, `kwt_`, `tmv_`, `kwa_`, `kwrel_`, `kwam_`, `kwtcl_`).

## 1. Database (`omi_seo_ai`)

| Báº£ng | Model |
|---|---|
| `seo_keyword_workspaces` | `SeoKeywordWorkspace` |
| `seo_keywords` | `SeoKiKeyword` |
| `seo_keyword_clusters` | `SeoKeywordCluster` |
| `seo_topics` | `SeoKiTopic` |
| `seo_topic_cluster_links` | `SeoTopicClusterLink` |
| `seo_keyword_relationships` | `SeoKeywordRelationship` |
| `seo_keyword_article_mappings` | `SeoKeywordArticleMapping` |
| `seo_topical_map_versions` | `SeoTopicalMapVersion` |
| `seo_keyword_analysis_operations` | `SeoKeywordAnalysisOperation` |

Migrations: `2026_07_27_170000_create_keyword_intelligence_tables.php` (báº£ng gá»‘c) + `2026_07_27_171000_enrich_keyword_intelligence_tables.php` (additive columns: `settings`/`summary` JSON trÃªn workspace, enrich scoring/suggested-* trÃªn cluster, `is_manual`/`is_primary`/`confidence` trÃªn article mapping).

## 2. Services (`Services/KeywordIntelligence/`)

| Service | Vai trÃ² |
|---|---|
| `KeywordNormalizationService` | Chuáº©n hoÃ¡ keyword, phÃ¡t hiá»‡n near-duplicate |
| `KeywordIntentClassifier` | PhÃ¢n loáº¡i search intent + funnel stage |
| `KeywordScoringService` | TÃ­nh relevance/opportunity/priority score |
| `KeywordClusterService` | Gom cluster theo strategy (balanced/tight/loose) |
| `KeywordImportService` | Import keyword rows, dedupe exact + near-duplicate |
| `KeywordExistingContentMapper` | Map keyword â†” `SeoArticle` hiá»‡n cÃ³ theo token title/slug (confidence high/medium/low), bá» qua mapping `is_manual` |
| `KeywordCannibalizationService` | PhÃ¡t hiá»‡n risk nhiá»u bÃ i cÃ¹ng keyword/cluster ([chi tiáº¿t](KEYWORD_CANNIBALIZATION.md)) |
| `TopicalMapBuilder` | XÃ¢y root + pillar theo intent, snapshot version ([chi tiáº¿t](TOPICAL_MAP.md)) |
| `KeywordWorkspaceAnalysisService` | Orchestrate toÃ n pipeline, ghi `SeoKeywordAnalysisOperation` |
| `KeywordToContentProjectConverter` | Convert cluster Ä‘Ã£ approved â†’ Content Project ([chi tiáº¿t](KEYWORD_TO_CONTENT_PROJECT.md)) |
| `Application\Quotas\KeywordIntelligenceQuotaGuard` | Giá»›i háº¡n theo `config/keyword_intelligence.php` (sá»‘ workspace/site, keyword/import, cluster/convert) |
| `Application\Support\KeywordIntelligenceTenantGuard` | Cháº·n truy cáº­p workspace khÃ¡c site |
| `Application\KeywordIntelligenceReadService` | Read surface (site_id, workspace_ref) â€” chá»‰ tráº£ public ref |
| `Agent\KeywordIntelligenceReadService` | Adapter cho `ContentProjectAgentGateway` (`AgentExecutionContext` + `input[]` â†’ gá»i read service trÃªn) |

## 3. Application layer (Commands/Handlers)

Namespace `Services/KeywordIntelligence/Application/{Commands,Handlers}`, implement `ContentProjectCommand` / `ContentProjectCommandHandler` giá»‘ng Content Project.

| Command | Handler | Ghi chÃº |
|---|---|---|
| `CreateKeywordWorkspaceCommand` | `CreateKeywordWorkspaceHandler` | Check quota `max_workspaces_per_site` |
| `ImportKeywordsCommand` | `ImportKeywordsHandler` | `preview=true` tráº£ preview khÃ´ng ghi DB |
| `AnalyzeKeywordWorkspaceCommand` | `AnalyzeKeywordWorkspaceHandler` | Cháº¡y toÃ n bá»™ `KeywordWorkspaceAnalysisService::analyze()` |
| `ApproveKeywordsCommand` | `ApproveKeywordsHandler` | Set `review_status` approved/rejected |
| `ApproveKeywordClustersCommand` | `ApproveKeywordClustersHandler` | Set `status` approved/excluded |
| `BuildTopicalMapCommand` | `BuildTopicalMapHandler` | Gá»i `TopicalMapBuilder::build()` Ä‘á»™c láº­p vá»›i analyze |
| `PreviewContentProjectFromClustersCommand` | `PreviewContentProjectFromClustersHandler` | Dry preview + confirmation token náº¿u vÆ°á»£t threshold |
| `CreateContentProjectFromKeywordClustersCommand` | `CreateContentProjectFromKeywordClustersHandler` | Convert tháº­t, cáº§n confirmation vá»›i actor agent/api hoáº·c vÆ°á»£t threshold |
| `ArchiveKeywordWorkspaceCommand` | `ArchiveKeywordWorkspaceHandler` | Workspace archived â†’ read-only, cháº·n import/analyze/convert má»›i |

Má»i handler káº¿ thá»«a `AbstractKeywordIntelligenceHandler`: resolve `workspace_ref` strict qua `KeywordIntelligencePublicRef`, `KeywordIntelligenceTenantGuard::assertCanAccessWorkspace()`, `assertNotArchived()`, map exception â†’ `KeywordIntelligenceActionCodes`.

Wiring: `ContentProjectCommandBusRegistrar` (map commandâ†’handler), `ContentProjectCapabilityRegistry` (`keyword_intelligence.*` write capabilities), `ContentProjectAgentCommandFactory` (build command tá»« agent input), `ContentProjectAgentGateway::READ_CAPABILITIES` + `executeRead()` (read capabilities), `ContentProjectAgentPolicy::requiredScope()` (`content-project:read` cho `get_*`/`list_*`, `content-project:write` cho pháº§n cÃ²n láº¡i).

Read capabilities (agent + MCP tool, Ä‘á»c-only, prefix `keyword_intelligence.`): `list_workspaces`, `get_workspace`, `list_keywords`, `list_clusters`, `get_topical_map`, `get_cannibalization`, `get_analysis_operation`. Táº¥t cáº£ Ä‘i qua `Agent\KeywordIntelligenceReadService` â†’ `Application\KeywordIntelligenceReadService` (site_id + workspace_ref, khÃ´ng leak numeric ID).

## 4. Filament UI

| Page | Slug | Ghi chÃº |
|---|---|---|
| `ListKeywordWorkspaces` | `keyword-intelligence` | Danh sÃ¡ch workspace theo site truy cáº­p Ä‘Æ°á»£c + form táº¡o workspace |
| `ViewKeywordWorkspace` | `keyword-intelligence/{workspace_ref}` | Tabs Overview/Keywords/Clusters/Topical map/Cannibalization; import, analyze, build map, approve/reject keyword+cluster, preview/convert, archive |

`canAccess()` dÃ¹ng `SeoAccessControl::canAccessManagerFeatures()`. Dispatch qua `app(ContentProjectCommandBus::class)->dispatch($command, $actorContext)` â€” khÃ´ng tá»± viáº¿t business logic trong page.

Lang keys: `seo-content-ai::filament.keyword_intelligence.*` (`lang/en/filament.php`, `lang/vi/filament.php`).

> **LÆ°u Ã½:** `ArchiveKeywordWorkspaceCommand` (archive **Keyword Workspace**) hoÃ n toÃ n Ä‘á»™c láº­p vá»›i "Destroy Workspace" khi archive má»™t `ContentProject` (dá»n AI workspace artifacts). Hai khÃ¡i niá»‡m khÃ´ng dÃ¹ng chung state/hÃ nh vi â€” xem [KEYWORD_TO_CONTENT_PROJECT.md](KEYWORD_TO_CONTENT_PROJECT.md).

## 5. Quotas

`config/keyword_intelligence.php` (merge vÃ o `seo-content-ai.keyword_intelligence`):

- `limits.max_workspaces_per_site`
- `limits.max_keywords_per_import`, `limits.max_keywords_per_workspace`
- `limits.max_clusters_per_convert`, `limits.convert_confirmation_threshold`
- `clustering.default_strategy`
- `topical_map.max_depth`
- `cannibalization.multi_mapping_threshold`

## Manual verification

```bash
$PHP_BIN vendor/bin/phpunit app/Addons/SeoContentAi/tests/Unit
php artisan optimize:clear
```

## Agent Workspace skills

Keyword Intelligence capabilities exposed as Agent slash skills â€” xem [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md) (`/import-keywords`, `/analyze-keywords`, `/build-topical-map`, `/preview-project`, â€¦). Availability: [AGENT_SKILLS.md](AGENT_SKILLS.md).

## Phase 4 â€” SERP Intelligence (additive)

Public refs thÃªm prefix `srpq_`, `srps_`, `srpr_`, `srpf_`, `srpe_`, `srpc_`, `srpg_` trÃªn `KeywordIntelligencePublicRef`.

Services: `Services/SerpIntelligence/` â€” collect, intent evidence, overlap validation, content gaps. CommandBus capabilities `serp_intelligence.*`.

Filament tab **SERP Intelligence** trÃªn `ViewKeywordWorkspace`. Docs: [SERP_INTELLIGENCE.md](SERP_INTELLIGENCE.md).

## Phase 5 â€” GSC Intelligence (additive)

Public refs thÃªm `gscp_`, `gscs_`, `gscq_`, `gscm_`, `gsca_`, `gsco_` trÃªn `KeywordIntelligencePublicRef`.

Unmapped query preview â†’ `ImportKeywordsCommand` / `AnalyzeSelectedKeywordsCommand` via `GscKeywordWorkspaceQueryPreviewService` (CommandBus `gsc_intelligence.preview_add_queries` / `add_queries_to_workspace`).

Agent/MCP: GSC **read** tools trÃªn Gateway/MCP catalog; writes trÃªn CommandBus cho app. Status: **PARTIAL** â€” xem [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).
