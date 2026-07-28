# Keyword Intelligence (Phase 1–2)

Phase 2 adds analysis engine (normalize→dedup→intent→score→map→cluster→cannibalization), operation lock, cluster merge/split/move, cannibalization issues table, and Filament tabs Existing Content + Analysis.

See also: [KEYWORD_ANALYSIS_OPERATIONS.md](KEYWORD_ANALYSIS_OPERATIONS.md), [KEYWORD_CLUSTERING.md](KEYWORD_CLUSTERING.md), [KEYWORD_CANNIBALIZATION.md](KEYWORD_CANNIBALIZATION.md).

## Manual override

`field_sources.{field}.source = manual` wins over analysis.

Bộ tính năng nghiên cứu từ khóa cho addon SEO Content AI: import → normalize → classify intent → score → map nội dung hiện có → cluster → xây topical map → phát hiện cannibalization → convert cluster đã duyệt thành Content Project.

Không thuộc `ContentProject` aggregate — dùng chung `ContentProjectCommandBus` / `ActorContext` / `ContentProjectActionResult` nhưng có `KeywordIntelligencePublicRef` riêng (prefix `kww_`, `kw_`, `kwc_`, `kwt_`, `tmv_`, `kwa_`, `kwrel_`, `kwam_`, `kwtcl_`).

## 1. Database (`omi_seo_ai`)

| Bảng | Model |
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

Migrations: `2026_07_27_170000_create_keyword_intelligence_tables.php` (bảng gốc) + `2026_07_27_171000_enrich_keyword_intelligence_tables.php` (additive columns: `settings`/`summary` JSON trên workspace, enrich scoring/suggested-* trên cluster, `is_manual`/`is_primary`/`confidence` trên article mapping).

## 2. Services (`Services/KeywordIntelligence/`)

| Service | Vai trò |
|---|---|
| `KeywordNormalizationService` | Chuẩn hoá keyword, phát hiện near-duplicate |
| `KeywordIntentClassifier` | Phân loại search intent + funnel stage |
| `KeywordScoringService` | Tính relevance/opportunity/priority score |
| `KeywordClusterService` | Gom cluster theo strategy (balanced/tight/loose) |
| `KeywordImportService` | Import keyword rows, dedupe exact + near-duplicate |
| `KeywordExistingContentMapper` | Map keyword ↔ `SeoArticle` hiện có theo token title/slug (confidence high/medium/low), bỏ qua mapping `is_manual` |
| `KeywordCannibalizationService` | Phát hiện risk nhiều bài cùng keyword/cluster ([chi tiết](KEYWORD_CANNIBALIZATION.md)) |
| `TopicalMapBuilder` | Xây root + pillar theo intent, snapshot version ([chi tiết](TOPICAL_MAP.md)) |
| `KeywordWorkspaceAnalysisService` | Orchestrate toàn pipeline, ghi `SeoKeywordAnalysisOperation` |
| `KeywordToContentProjectConverter` | Convert cluster đã approved → Content Project ([chi tiết](KEYWORD_TO_CONTENT_PROJECT.md)) |
| `Application\Quotas\KeywordIntelligenceQuotaGuard` | Giới hạn theo `config/keyword_intelligence.php` (số workspace/site, keyword/import, cluster/convert) |
| `Application\Support\KeywordIntelligenceTenantGuard` | Chặn truy cập workspace khác site |
| `Application\KeywordIntelligenceReadService` | Read surface (site_id, workspace_ref) — chỉ trả public ref |
| `Agent\KeywordIntelligenceReadService` | Adapter cho `ContentProjectAgentGateway` (`AgentExecutionContext` + `input[]` → gọi read service trên) |

## 3. Application layer (Commands/Handlers)

Namespace `Services/KeywordIntelligence/Application/{Commands,Handlers}`, implement `ContentProjectCommand` / `ContentProjectCommandHandler` giống Content Project.

| Command | Handler | Ghi chú |
|---|---|---|
| `CreateKeywordWorkspaceCommand` | `CreateKeywordWorkspaceHandler` | Check quota `max_workspaces_per_site` |
| `ImportKeywordsCommand` | `ImportKeywordsHandler` | `preview=true` trả preview không ghi DB |
| `AnalyzeKeywordWorkspaceCommand` | `AnalyzeKeywordWorkspaceHandler` | Chạy toàn bộ `KeywordWorkspaceAnalysisService::analyze()` |
| `ApproveKeywordsCommand` | `ApproveKeywordsHandler` | Set `review_status` approved/rejected |
| `ApproveKeywordClustersCommand` | `ApproveKeywordClustersHandler` | Set `status` approved/excluded |
| `BuildTopicalMapCommand` | `BuildTopicalMapHandler` | Gọi `TopicalMapBuilder::build()` độc lập với analyze |
| `PreviewContentProjectFromClustersCommand` | `PreviewContentProjectFromClustersHandler` | Dry preview + confirmation token nếu vượt threshold |
| `CreateContentProjectFromKeywordClustersCommand` | `CreateContentProjectFromKeywordClustersHandler` | Convert thật, cần confirmation với actor agent/api hoặc vượt threshold |
| `ArchiveKeywordWorkspaceCommand` | `ArchiveKeywordWorkspaceHandler` | Workspace archived → read-only, chặn import/analyze/convert mới |

Mọi handler kế thừa `AbstractKeywordIntelligenceHandler`: resolve `workspace_ref` strict qua `KeywordIntelligencePublicRef`, `KeywordIntelligenceTenantGuard::assertCanAccessWorkspace()`, `assertNotArchived()`, map exception → `KeywordIntelligenceActionCodes`.

Wiring: `ContentProjectCommandBusRegistrar` (map command→handler), `ContentProjectCapabilityRegistry` (`keyword_intelligence.*` write capabilities), `ContentProjectAgentCommandFactory` (build command từ agent input), `ContentProjectAgentGateway::READ_CAPABILITIES` + `executeRead()` (read capabilities), `ContentProjectAgentPolicy::requiredScope()` (`content-project:read` cho `get_*`/`list_*`, `content-project:write` cho phần còn lại).

Read capabilities (agent + MCP tool, đọc-only, prefix `keyword_intelligence.`): `list_workspaces`, `get_workspace`, `list_keywords`, `list_clusters`, `get_topical_map`, `get_cannibalization`, `get_analysis_operation`. Tất cả đi qua `Agent\KeywordIntelligenceReadService` → `Application\KeywordIntelligenceReadService` (site_id + workspace_ref, không leak numeric ID).

## 4. Filament UI

| Page | Slug | Ghi chú |
|---|---|---|
| `ListKeywordWorkspaces` | `keyword-intelligence` | Danh sách workspace theo site truy cập được + form tạo workspace |
| `ViewKeywordWorkspace` | `keyword-intelligence/{workspace_ref}` | Tabs Overview/Keywords/Clusters/Topical map/Cannibalization; import, analyze, build map, approve/reject keyword+cluster, preview/convert, archive |

`canAccess()` dùng `SeoAccessControl::canAccessManagerFeatures()`. Dispatch qua `app(ContentProjectCommandBus::class)->dispatch($command, $actorContext)` — không tự viết business logic trong page.

Lang keys: `seo-content-ai::filament.keyword_intelligence.*` (`lang/en/filament.php`, `lang/vi/filament.php`).

> **Lưu ý:** `ArchiveKeywordWorkspaceCommand` (archive **Keyword Workspace**) hoàn toàn độc lập với "Destroy Workspace" khi archive một `ContentProject` (dọn AI workspace artifacts). Hai khái niệm không dùng chung state/hành vi — xem [KEYWORD_TO_CONTENT_PROJECT.md](KEYWORD_TO_CONTENT_PROJECT.md).

## 5. Quotas

`config/keyword_intelligence.php` (merge vào `seo-content-ai.keyword_intelligence`):

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

## Phase 4 — SERP Intelligence (additive)

Public refs thêm prefix `srpq_`, `srps_`, `srpr_`, `srpf_`, `srpe_`, `srpc_`, `srpg_` trên `KeywordIntelligencePublicRef`.

Services: `Services/SerpIntelligence/` — collect, intent evidence, overlap validation, content gaps. CommandBus capabilities `serp_intelligence.*`.

Filament tab **SERP Intelligence** trên `ViewKeywordWorkspace`. Docs: [SERP_INTELLIGENCE.md](SERP_INTELLIGENCE.md).

## Phase 5 — GSC Intelligence (additive)

Public refs thêm `gscp_`, `gscs_`, `gscq_`, `gscm_`, `gsca_`, `gsco_` trên `KeywordIntelligencePublicRef`.

Unmapped query preview → `ImportKeywordsCommand` / `AnalyzeSelectedKeywordsCommand` via `GscKeywordWorkspaceQueryPreviewService` (CommandBus `gsc_intelligence.preview_add_queries` / `add_queries_to_workspace`).

Agent/MCP: GSC **read** tools trên Gateway/MCP catalog; writes trên CommandBus cho app. Status: **PARTIAL** — xem [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).
