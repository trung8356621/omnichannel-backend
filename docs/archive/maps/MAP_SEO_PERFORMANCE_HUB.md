> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# SeoContentAi â€” Performance & R&D Hub

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [Team & PhÃ¢n quyá»n](MAP_SEO_TEAM.md) Â· [Content Projects & Workflow](MAP_SEO_PROJECTS.md) Â· [Settings, Prompts & AI Connections](MAP_SEO_SETTINGS.md) Â· [Domain Management](MAP_SEO_DOMAIN.md)

---

## 1. Tá»•ng quan

**Performance Hub** lÃ  trang Filament Livewire phÃ¢n tÃ­ch SEO: GSC KPI, rankings, Quick Wins, SERP changes. **AI Keyword Discovery** vÃ  **Keyword Cannibalization** Ä‘Ã£ tÃ¡ch ra khá»i page nÃ y.

| ThÃ´ng tin | GiÃ¡ trá»‹ |
|-----------|---------|
| **URL panel** | `/seo/{connection_hash}/performance-hub` |
| **Route name** | `filament.seo.pages.performance-hub` |
| **Slug Filament** | `performance-hub` |
| **Livewire page** | `Filament/Pages/SeoPerformanceHub.php` |
| **View** | `resources/views/seo/performance-hub.blade.php` + partials `performance-hub/` |
| **CSS** | `resources/css/performance-hub.css` |
| **Navigation** | áº¨n Filament nav (`shouldRegisterNavigation = false`); sidebar **Keywords â†’ SEO Performance** |
| **Quyá»n** | `SeoAccessControl::canAccessPlannerFeatures()` (Planner+) + `SeoPlannerPermissionMiddleware` |
| **Domain scope** | `SeoAccessControl::globalSiteId()` â€” domain header bar panel SEO |

**Trang liÃªn quan (tÃ¡ch khá»i Performance Hub):**

| Feature | URL | Class |
|---------|-----|-------|
| AI Keyword Discovery | `/seo/{hash}/keywords/ai-discovery` | `Filament/Pages/AiKeywordDiscovery.php` |
| Keyword Cannibalization | `/seo/{hash}/keywords/cannibalization` | `KeywordResource/Pages/KeywordCannibalizationWorkspace.php` |

Trang **khÃ´ng** dÃ¹ng React app full-page â€” UI chÃ­nh lÃ  Blade + Livewire + Alpine. **GSC chart** mount **ApexCharts** qua Vite entry `resources/js/performance-hub-gsc-chart.js`.

---

## 2. Routing

### 2.1 Route chÃ­nh (Filament auto-discover)

Filament panel `seo` mount táº¡i `seo/{connection_hash}` (`SeoPanelProvider::panel()`). Page Ä‘Æ°á»£c discover tá»« `Filament/Pages/`:

```text
GET /seo/{connection_hash}/performance-hub
    â†’ SeoPerformanceHub (Livewire)
    â†’ route name: filament.seo.pages.performance-hub
```

`SeoPerformanceHub` extends `SeoPanelPage` â†’ URL luÃ´n merge `connection_hash` qua `InteractsWithSeoConnectionRoutes` / `SeoConnectionContext::mergePanelRouteParameters()`.

### 2.2 Legacy redirect (trong panel group)

ÄÄƒng kÃ½ trong `SeoPanelProvider` (prefix `seo/{connection_hash}`):

| Legacy path | Redirect |
|-------------|----------|
| `/seo/{hash}/keywords/workspace-3` | `../keywords/ai-discovery` (`seo.keywords.workspace-3-legacy`) |
| `/seo/{hash}/keywords/workspace-4` | `../keywords/cannibalization` (`seo.keywords.workspace-4-legacy`) |
| `/seo/{hash}/settings/ai` | `../settings/api` (`seo.settings.ai-legacy`) |

### 2.3 Controller stub (chÆ°a mount route)

`Http/Controllers/SeoPerformanceHubController.php` â€” `__invoke()` redirect tá»›i `SeoPerformanceHub::getUrl()`. **Hiá»‡n chÆ°a Ä‘Æ°á»£c Ä‘Äƒng kÃ½** trong `routes` / `SeoPanelProvider`; entry thá»±c táº¿ lÃ  Filament page á»Ÿ trÃªn.

Middleware `SeoPlannerPermissionMiddleware` cÅ©ng khai bÃ¡o pattern `seo.performance.*` (dá»± phÃ²ng route controller tÆ°Æ¡ng lai).

---

## 3. Kiáº¿n trÃºc UI (2 source tabs + sub-tabs)

**Source tabs** (URL `?source=`): sinh Ä‘á»™ng tá»« `SeoProviderRegistry` + `SeoProviderConnectionStatusService::performanceTabsForUser()` â€” `gsc` | `serpapi` | `serper` | `searchapi` | `keywords_everywhere` (khi configured). **KhÃ´ng** tab `seranking` (settings only, `performanceTabSupported=false`). Order theo `priority`. Fallback: `SeoPerformanceDashboardService::resolveSourceOrFallback()`.

**Dashboard sections** theo registry `dashboardSections` / `PerformanceHubSectionKey` â€” Blade chá»‰ render section cÃ³ trong allowlist (`rank_kpis`, `rankings_table`, `integration_state`, â€¦). Keywords Everywhere: tab metrics riÃªng + `integration_state` khi chÆ°a cÃ³ adapter (`supported=true`, `implemented=false`).

**Rank keyword groups** (URL `?rank_group=`): entity global `SeoRankKeywordGroup` â€” dÃ¹ng chung má»i SERP provider (khÃ´ng GSC). KhÃ´ng cÃ³ `site_id`/`domain_id`. Scope theo `created_by` / account owner (`SeoAccessControl::accountSiteOwnerId()`). Snapshots/runs gáº¯n `rank_group_id` + `rank_group_item_id` + `provider`.

**Global domain selector** (`GlobalSeoBar`): hiá»‡n khi `source=gsc` hoáº·c trang khÃ¡c; áº©n khi `filament.seo.pages.performance-hub` + `source` lÃ  SERP provider (`SeoAccessControl::shouldShowGlobalSitePicker()`).

**Sub-tabs** (URL `?tab=`) theo source â€” khÃ´ng trá»™n metric GSC vá»›i rank tracker.

```mermaid
flowchart TB
    PAGE["SeoPerformanceHub"]
    PAGE --> SRC["Source tabs ?source="]
    SRC --> GSC["GSC: KPI/query/distribution"]
    SRC --> RANK["Rank: KPI/snapshots/SERP"]
    GSC --> GSC_TABS["queries | quick-wins"]
    RANK --> RANK_TABS["rankings | serp-changes"]
```

| Source | URL `source` | Sub-tab `tab` | Dá»¯ liá»‡u |
|--------|--------------|---------------|--------|
| **Google Search Console** | `gsc` | `queries`, `quick-wins` | **Legacy:** `gsc_query_snapshot` (site meta). **Additive:** GSC Intelligence overlay (`gsc-intelligence-panel`) â€” Sync CSV preview; Overview/Queries/Pages/Opportunities placeholder â€” facts trÃªn `omi_seo_ai` khi import/sync via CommandBus (xem [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md)) |
| **SERP providers** | `serpapi` / `serper` / `searchapi` | `rankings`, `serp-changes` | `KeywordRankSnapshot` + `SeoRankKeywordGroup` (khÃ´ng filter domain header) |

Partials: `source-tabs`, `gsc-connection-strip`, `gsc-kpi-cards`, `gsc-chart`, `gsc-distribution`, `gsc-queries-table`, `rank-connection-strip` (provider icon strip inline), `rank-kpi-cards`, `ranking-distribution`, `rank-toolbar` (Cháº¡y nhÃ³m + group selector, cáº¡nh rank sub-tabs), `rankings-table`, `serp-changes-table`, `advanced-analysis` (toggle Alpine â€” chá»©a `visibility-chart` + `provider-comparison` khi eligible), `rank-group-modal`, `gsc-bulk-sync-summary`.

**Thá»© tá»± rank provider tab (khÃ´ng scroll chart tráº¯ng):**

1. Source tabs  
2. Provider connection strip  
3. KPI cards (giá»¯ card Visibility)  
4. PhÃ¢n bá»‘ thá»© háº¡ng (`ranking-distribution`)  
5. Rank toolbar + sub-tabs (`rankings` \| `serp-changes`)  
6. Rankings table / SERP changes table  
7. NÃºt **PhÃ¢n tÃ­ch nÃ¢ng cao** + ná»™i dung collapsed (chá»‰ khi `advanced_analysis.has_any`)

**PhÃ¢n tÃ­ch nÃ¢ng cao (`advanced_analysis` trong `buildRankState()`):**

| Key | Eligible khi | Render Blade |
|-----|--------------|--------------|
| `organic_visibility` | NhÃ³m + `target_domain` + â‰¥2 `keyword_rank_check_runs` `status=completed` cÃ³ rank snapshot (Ä‘áº¿m theo `run_id`, khÃ´ng Ä‘áº¿m keyword rows) | Partial `visibility-chart` â€” khÃ´ng heading/chart/empty khi `eligible=false` |
| `provider_comparison` | â‰¥2 SERP provider configured + cÃ³ rows comparison (`comparison_batch` URL) + `SeoAccessControl::canAccessManagerFeatures()` (debug/manager) | Partial `provider-comparison` â€” tiÃªu Ä‘á» **So sÃ¡nh nhÃ  cung cáº¥p** |
| `has_any` | Má»™t trong hai trÃªn eligible | Partial `advanced-analysis` â€” nÃºt ghost **Hiá»‡n phÃ¢n tÃ­ch nÃ¢ng cao**, máº·c Ä‘á»‹nh `expanded: false` (Alpine) |

```php
advanced_analysis: [
    'has_any' => bool,
    'organic_visibility' => ['eligible', 'successful_run_count', 'data' => [...]],
    'provider_comparison' => ['eligible', 'provider_count', 'data' => [...]],
]
```

| Model / Service | Vai trÃ² |
|-----------------|--------|
| `Models/SeoRankKeywordGroup` | NhÃ³m rank global: name, country/language/location/device, `target_domain` nullable |
| `Models/SeoRankKeywordGroupItem` | Keyword â†” group (unique per group) |
| `Services/SeoRankKeywordGroupService` | CRUD, duplicate/archive, add keywords idempotent |
| `Services/KeywordRankCheckService::dispatchForGroup()` | Queue rank/allintitle/volume theo group + provider (queue `seo`, `metadata.metrics`) |
| `Services/KeywordRankCheckService::reconcileStaleRuns()` | Dá»n run káº¹t `pending`/`running` trÆ°á»›c dispatch hoáº·c khi mount page |
| `Services/SeoProviderRegistry` | Provider definitions: capabilities, sections, actions, priority, `performanceSourceKey` |
| `Services/SeoProviderCapabilityResolver` | `supported` / `implemented` / `configured` / `available`; dispatch validation |
| `Services/SeoProviderConnectionStatusService` | Configured/active + dynamic performance tabs |
| `Services/SerpProviderCapabilityService` | Legacy toolbar caps + `filterDispatchableMetrics` (delegate resolver) |
| `Services/KeywordSearchVolumeService` | Search volume tháº­t qua DataForSEO Google Ads API; `not_configured` khi chÆ°a setup |
| `Services/KeywordSerpChangeAnalysisService` | Tab SERP changes: `entered` / `lost` / `rank_delta` / `url_changed` (cáº§n â‰¥2 snapshot) |
| `Models/KeywordGroupMetricSnapshot` | Snapshot metric `allintitle` + `search_volume` tÃ¡ch khá»i rank snapshot |
| `Jobs/RunKeywordGroupMetricBatchJob` | Batch allintitle / search volume (queue `seo`) |
| `SeoPerformanceDashboardService::buildRankingRows()` | Merge rank snapshot + metric snapshots; status semantics Ä‘áº§y Ä‘á»§ |
| `SeoPerformanceDashboardService::buildRankKpiCards()` | KPI chá»‰ khi cÃ³ position sá»‘; khÃ´ng cÃ³ â†’ label `empty_no_data` / `empty_no_target_domain` |

**Queue worker rank:** job `RunKeywordRankCheckBatchJob` / `RunSingleKeywordRankCheckJob` / `RunKeywordGroupMetricBatchJob` dÃ¹ng `->onQueue('seo')`. Worker pháº£i listen `seo` (ops CLI / Supervisor â€” khÃ´ng cÃ²n Queue Manager UI hay banner worker offline).

**Modal nhÃ³m (`rank-group-modal`):** Alpine sá»Ÿ há»¯u `open` â€” báº­t/táº¯t khung **ngay** (`open = true/false`), khÃ´ng chá» Livewire round-trip. Livewire chá»‰ load/persist: `openGroupModal()`, `loadGroupModalData()`, `saveGroupModal()`, `closeGroupModal()`. Alpine state: `modalMode`, `localLoading`, title/submit label client-side. Edit: skeleton qua `localLoading || $wire.groupModalLoading`. Layout: TÃªn nhÃ³m\|Thiáº¿t bá»‹ (gap `form-grid--name-device`), MÃ´ táº£ textarea, Quá»‘c gia\|NgÃ´n ngá»¯\|Vá»‹ trÃ­, Target domain select + custom domain. Rule: `.cursor/rules/modal-alpine.mdc`.

**Run nhÃ³m (`rank-toolbar`):** popover chá»n metric (`rank`, `allintitle`, `search_volume`). KhÃ´ng dispatch metric khÃ´ng há»— trá»£.

**Metric availability (thá»±c táº¿):**
- **Rank:** SerpApi / Serper / SearchApi (active provider).
- **Allintitle:** SerpApi + SearchApi (`search_information.total_results`); Serper â†’ `not_supported`.
- **Search Volume:** DataForSEO Google Ads API khi connection configured; khÃ´ng suy tá»« GSC/Trends/total_results.

Lazy state: `#[Computed] gscDashboardState` / `rankDashboardState` â€” chá»‰ build khi source active.

### Sidebar navigation (Keywords submenu)

Hook `PanelsRenderHook::SIDEBAR_NAV_END` inject `filament/hooks/seo-sidebar-keywords-nav.blade.php`:

- **Content Editor** â†’ `KeywordResource::getUrl('index')` â€” gá»“m workspace tabs (`/keywords`, `/focus`, `/anchor-audit`, `/workspace-2`, `/cannibalization`)
- **AI Keyword Discovery** â†’ `AiKeywordDiscovery::getUrl()`
- **SEO Performance** â†’ `SeoPerformanceHub::getUrl()`

Chá»‰ render khi `SeoAccessControl::canAccessPlannerFeatures()`.

### Keywords workspace tab â€” Cannibalization

| ThÃ´ng tin | GiÃ¡ trá»‹ |
|-----------|---------|
| **URL** | `/seo/{hash}/keywords/cannibalization` |
| **Route** | `filament.seo.resources.keywords.cannibalization` |
| **Page** | `KeywordResource/Pages/KeywordCannibalizationWorkspace.php` |
| **View** | `filament/resources/keywords/pages/keyword-cannibalization-workspace.blade.php` |
| **Nav trait** | `HasKeywordWorkspaceNavigation` â€” tab `cannibalization` trong workspace bar |
| **Service** | `KeywordCannibalizationService` â†’ `SeoPerformanceHubService::detectCannibalization()` |

---

## 4. Backend â€” `SeoPerformanceHub.php`

### 4.1 URL query state (`#[Url]`)

| Property | URL key | Máº·c Ä‘á»‹nh | MÃ´ táº£ |
|----------|---------|----------|-------|
| `dataSource` | `source` | auto | `gsc` hoáº·c SERP provider key |
| `rankGroupId` | `rank_group` | auto first group | NhÃ³m tá»« khÃ³a (provider tabs) |
| `activeTab` | `tab` | `queries` / `rankings` | Sub-tab theo source |
| `querySortBy` | `sort` | `impressions` | Cá»™t sort báº£ng GSC |
| `querySortDir` | `dir` | `desc` | HÆ°á»›ng sort |
| `gscQuerySearch` | `gsc_q` | `''` | Search báº£ng Queries (tab GSC) |
| `positionBucket` | `position_bucket` | `null` | Filter distribution: `1-3` \| `4-10` \| `11-20` \| `21-50` \| `51-100` |
| `gscPage` | `gsc_page` | `1` | Trang pagination Queries |
| `gscPerPage` | `gsc_per_page` | `25` | Page size Queries (`10` \| `25` \| `50` \| `100`) |
| `gscChartMetric` | `gsc_metric` | `clicks` | Metric chart GSC: `clicks` \| `impressions` \| `ctr` \| `position` |
| `keywordSearch` | `q` | `''` | Filter keyword (rank tab) |

### 4.2 Computed properties (Livewire)

| Property | Service | MÃ´ táº£ |
|----------|---------|-------|
| `gscDashboardState` | `SeoPerformanceDashboardService::buildGscState()` | Connection strip, GSC KPI, distribution, queries, quick wins |
| `rankDashboardState` | `SeoPerformanceDashboardService::buildRankState()` | Provider strip, rank KPI, distribution, rankings, SERP changes, `advanced_analysis` |

### 4.3 Livewire actions

| Method | Source | MÃ´ táº£ |
|--------|--------|-------|
| `setDataSource($source)` | Hub | Äá»•i `?source=` + reset sub-tab |
| `setActiveTab($tab)` | Hub | Sub-tab queries/quick-wins hoáº·c rankings/serp-changes |
| `setPositionBucket($bucket)` | GSC | Toggle filter distribution â†’ báº£ng Queries; reset `gsc_page` |
| `clearPositionBucket()` | GSC | XÃ³a filter position |
| `gotoGscPage($page)` | GSC | Pagination Queries |
| `setGscPerPage($perPage)` | GSC | Äá»•i page size; reset page 1 |
| `setGscChartMetric($metric)` | GSC | Äá»•i metric chart |
| `syncGscData()` | GSC | `ensureSiteMapped()` â†’ `syncSiteWithDetails()` domain hiá»‡n táº¡i |
| `syncAllMappedGscDomains()` | GSC | `autoMapAndSyncAll()` â€” auto-map má»i domain accessible rá»“i sync; panel `gsc-bulk-sync-summary` |
| `retryGscSyncForSite($siteId)` | GSC | Retry 1 domain failed |
| `runKeywordRankCheck()` | Rank | Popover chá»n metrics â†’ `dispatchForGroup(metrics)` |
| `prepareGroupModal()` / `loadGroupModalData()` / `retryLoadGroupModal()` | Rank | Modal create/edit + loading guard |
| `saveGroupModal()` / `duplicateRankGroup()` / â€¦ | Rank | CRUD nhÃ³m qua `SeoRankKeywordGroupService` |

`/keywords` â€” row action `add_to_rank_group` + bulk `add_to_rank_group` (`KeywordResource`).

Domain resolve GSC: `resolveSiteId()` â†’ `SeoAccessControl::globalSiteId()`. Provider rank: **khÃ´ng** dÃ¹ng global domain; dÃ¹ng `rankGroupId`.

---

## 5. Service â€” `SeoPerformanceHubService.php`

Business logic GSC / Quick Wins / Cannibalization / push keyword.

### 5.1 Nguá»“n dá»¯ liá»‡u GSC

Äá»c snapshot JSON tá»« **core** `sites` meta key `gsc_query_snapshot` (khÃ´ng pháº£i DB `omi_seo_ai`):

```json
{
  "property_url": "sc-domain:example.com",
  "date_start": "2026-06-01",
  "date_end": "2026-06-28",
  "synced_at": "2026-07-11T10:00:00Z",
  "chart_status": "ok",
  "kpis": {
    "total_clicks": 0,
    "total_impressions": 0,
    "avg_ctr": 0.0,
    "avg_position": null,
    "total_queries": 0
  },
  "queries": [
    {
      "query": "example keyword",
      "clicks": 10,
      "impressions": 100,
      "ctr": 10.0,
      "position": 12.5
    }
  ],
  "timeseries": {
    "period_days": 28,
    "current_start": "2026-06-01",
    "current_end": "2026-06-28",
    "previous_start": "2026-05-04",
    "previous_end": "2026-05-31",
    "current": [
      { "date": "2026-06-01", "clicks": 1, "impressions": 10, "ctr": 10.0, "position": 12.5 }
    ],
    "previous": []
  }
}
```

- Náº¿u `kpis` cÃ³ sáºµn â†’ dÃ¹ng trá»±c tiáº¿p.
- Náº¿u chá»‰ cÃ³ `queries` â†’ aggregate KPI runtime.
- `timeseries` (backward-compatible): sync GSC dimension `date` 28 ngÃ y + previous period cÃ¹ng Ä‘á»™ dÃ i; `chart_status`: `ok` \| `empty` \| `failed`.
- KhÃ´ng cÃ³ snapshot â†’ `has_data: false`, UI hiá»‡n message `performance_hub.gsc_empty`.

**Queries table:** `GscQueriesTableService` â€” distribution counts trÃªn full dataset; filter `position_bucket` + search â†’ sort â†’ paginate (`LengthAwarePaginator` logic trÃªn collection). Partial: `gsc-distribution`, `gsc-queries-table`, `gsc-queries-pagination`.

**GSC chart:** `SeoPerformanceHubService::getGscPerformanceChart()` â†’ payload ApexCharts; JS `performance-hub-gsc-chart.js` (Livewire `commit` hook, destroy/recreate instance).

Scope: `Site::find($siteId)` + `SeoAccessControl::canAccessSite($siteId)`.

### 5.2 Quick Wins

Filter query GSC:

- `position` âˆˆ [11, 20]
- `impressions` > 0

Fallback khi khÃ´ng cÃ³ GSC: láº¥y keyword site tá»« `Keyword` + search volume (`KeywordMetaRepository::getSiteSearchVolume`), giáº£ position 15.

### 5.3 Cannibalization

QuÃ©t `SeoArticle` (+ eager `articleMetas` key `seo_focus_keyword`), normalize phrase qua `Keyword::normalizeFocusPhrase()`, group theo lowercase phrase, chá»‰ giá»¯ group > 1 bÃ i. Link edit qua `ArticleResource::getUrl('edit', ...)`.

### 5.4 Push keyword

`pushKeywordToEditor()` â†’ `KeywordPersistenceService::upsert()` vá»›i metrics `performance_hub_source: quick_wins`.

---

## 6. AI Keyword Discovery (page riÃªng)

| Class | Vai trÃ² |
|-------|---------|
| `Filament/Pages/AiKeywordDiscovery.php` | Page `/keywords/ai-discovery` |
| `Filament/Concerns/InteractsWithAiKeywordDiscovery.php` | Trait gom Livewire actions discovery |
| `AiKeywordDiscoveryService` | Prompt Gemini, parse gá»£i Ã½ keyword |
| `KeywordPersistenceService` | Upsert keyword + discovery metrics |
| `CreateArticlesFromTaskService` | Batch táº¡o draft articles |

Model AI: `SeoAiModel` + `ApiConnection` (xem [MAP_SEO_SETTINGS.md](MAP_SEO_SETTINGS.md)).

---

## 7. PhÃ¢n quyá»n & middleware

```text
Request â†’ Filament panel middleware stack
       â†’ authMiddleware: Authenticate, CheckMainRole, SeoPlannerPermissionMiddleware
```

`SeoPlannerPermissionMiddleware` cháº·n náº¿u role < Planner cho:

- Route `filament.seo.pages.performance-hub`
- Path `seo/*/performance-hub` (+ wildcard)
- (Dá»± phÃ²ng) `seo.performance.*`

Page-level: `SeoPerformanceHub::canAccess()` â†’ `canAccessPlannerFeatures()`.

Mutations (push keyword, import, create draft): thÃªm `canMutateInSeoPanel()`.

---

## 8. File map nhanh

| File | Vai trÃ² |
|------|---------|
| `Filament/Pages/SeoPerformanceHub.php` | Livewire page performance tabs |
| `Services/SeoPerformanceHubService.php` | GSC KPI/query/chart, quick wins, `detectCannibalization()` |
| `Services/GscQueriesTableService.php` | Distribution buckets, filter/sort/paginate Queries |
| `Services/SeoPerformanceDashboardService.php` | Dashboard state rankings/SERP/GSC connections |
| `Services/GoogleSearchConsoleBulkSyncService.php` | Auto-map + bulk sync GSC; `ensureSiteMapped()` |
| `Services/GoogleSearchConsoleSyncService.php` | GSC API â†’ snapshot `gsc_query_snapshot` |
| `resources/js/performance-hub-gsc-chart.js` | ApexCharts GSC trend chart (Vite) |
| `Services/KeywordCannibalizationService.php` | Wrapper cannibalization cho keywords tab |
| `KeywordResource/Pages/KeywordCannibalizationWorkspace.php` | Tab Cannibalization trÃªn `/keywords` |
| `Filament/Pages/AiKeywordDiscovery.php` | Page AI Discovery riÃªng |
| `Http/Middleware/SeoPlannerPermissionMiddleware.php` | RBAC Planner+ |
| `resources/views/seo/performance-hub.blade.php` | UI performance tabs + partials |
| `resources/views/seo/performance-hub/partials/advanced-analysis.blade.php` | Toggle Alpine phÃ¢n tÃ­ch nÃ¢ng cao (collapsed máº·c Ä‘á»‹nh) |
| `resources/views/seo/performance-hub/partials/visibility-chart.blade.php` | Biá»ƒu Ä‘á»“ Organic visibility â€” chá»‰ render khi eligible + `has_data` |
| `resources/views/seo/performance-hub/partials/provider-comparison.blade.php` | So sÃ¡nh nhÃ  cung cáº¥p (manager/debug) |
| `resources/views/seo/performance-hub/partials/rank-toolbar.blade.php` | Toolbar rank inline cáº¡nh sub-tabs |
| `resources/views/filament/hooks/seo-sidebar-keywords-nav.blade.php` | Sidebar Keywords dropdown |
| `Services/KeywordRankCheckService.php` | Dispatch + `reconcileStaleRuns()` cho group rank check |
| `Services/SeoRankKeywordGroupService.php` | CRUD nhÃ³m keyword rank global |
| `Models/SeoRankKeywordGroup.php` / `SeoRankKeywordGroupItem.php` | NhÃ³m + item keyword rank |
| `Jobs/RunKeywordRankCheckBatchJob.php` | Batch rank check (queue `seo`) |
| `Jobs/RunKeywordGroupMetricBatchJob.php` | Batch allintitle / search volume |
| `Services/KeywordGroupMetricSnapshotWriter.php` | Persist metric snapshots |
| `Services/KeywordSearchVolumeService.php` | DataForSEO volume resolver |
| `Services/KeywordSerpChangeAnalysisService.php` | SERP change semantics |
| `Services/SeoProviderRegistry.php` | Provider definitions + capability matrix |
| `Services/SeoProviderCapabilityResolver.php` | Capability states + dispatch gate |
| `Services/SeoProviderConnectionStatusService.php` | Connection status + dynamic tabs |
| `Services/SeoExtendedProviderConnectionService.php` | Keywords Everywhere settings (data adapter chÆ°a implement) |
| `Services/SerpProviderCapabilityService.php` | Toolbar/dispatch facade |
| `resources/views/seo/performance-hub/partials/integration-state.blade.php` | Partial implementation empty state |
| `resources/views/seo/performance-hub/partials/keyword-metrics-toolbar.blade.php` | Group selector tab keyword metrics |
| `Enums/KeywordGroupMetricType.php` / `KeywordMetricStatus.php` | Metric type + UI status |
| `database/migrations/2026_07_12_110000_create_keyword_group_metric_snapshots_table.php` | Báº£ng metric snapshots |
| `tests/Unit/SeoProviderRegistryTest.php` | Registry unique keys + capability semantics |
| `tests/Unit/SerpAllintitleQueryTest.php` | Allintitle query escape |
| `tests/Unit/SerpProviderCapabilityServiceTest.php` | Capability contract |
| `tests/Unit/KeywordSerpChangeAnalysisServiceTest.php` | SERP changes guard |
| `Providers/SeoPanelProvider.php` | Legacy redirects, sidebar hook |
| `tests/Unit/KeywordRankCheckServiceTest.php` | Test reconcile run káº¹t |
| `tests/Unit/SeoRankKeywordGroupServiceTest.php` | Test CRUD / add keyword group |
| `tests/Unit/SeoPerformanceDashboardServiceTest.php` | GSC/rank state, `advanced_analysis` eligibility, layout blade order |
| `tests/Unit/SeoAccessControlDomainPickerTest.php` | áº¨n domain picker khi source SERP provider |

---

## 9. Query URL vÃ­ dá»¥

```text
/seo/abc123.../performance-hub
/seo/abc123.../performance-hub?source=gsc&tab=queries
/seo/abc123.../performance-hub?source=gsc&tab=queries&position_bucket=11-20&gsc_page=1&gsc_per_page=25&gsc_q=brand
/seo/abc123.../performance-hub?source=gsc&gsc_metric=impressions
/seo/abc123.../performance-hub?tab=quick-wins
/seo/abc123.../performance-hub?sort=clicks&dir=asc
/seo/abc123.../keywords/ai-discovery
/seo/abc123.../keywords/cannibalization
```

Legacy:

```text
/seo/abc123.../keywords/workspace-3  â†’  .../keywords/ai-discovery
/seo/abc123.../keywords/workspace-4  â†’  .../keywords/cannibalization
/seo/abc123.../performance-hub?tab=ai-discovery  â†’  .../keywords/ai-discovery
/seo/abc123.../performance-hub?tab=cannibalization  â†’  .../keywords/cannibalization
```
