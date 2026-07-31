> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# SeoContentAi â€” Article SEO Audit

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [React Editor & EditArticle](MAP_SEO_EDITOR.md) Â· [SEO Scoring](MAP_SEO_EDITOR_SCORING.md) Â· [Content Projects & Workflow](MAP_SEO_PROJECTS.md) Â· [WordPress sync](MAP_SEO_WP.md) Â· [Team & PhÃ¢n quyá»n](MAP_SEO_TEAM.md)

---

## 1. Tá»•ng quan

**Article SEO Audit** lÃ  trang Filament Livewire giÃºp content team **quÃ©t vÃ  lá»c** cÃ¡c bÃ i viáº¿t chÆ°a Ä‘áº¡t chuáº©n SEO ká»¹ thuáº­t, **phÃ¢n vÃ o Content Project** Ä‘á»ƒ viáº¿t láº¡i/tá»‘i Æ°u, vÃ  **xem láº¡i danh sÃ¡ch bÃ i Ä‘Ã£ duyá»‡t**.

| ThÃ´ng tin | GiÃ¡ trá»‹ |
|-----------|---------|
| **URL panel** | `/seo/articles/optimal` |
| **Slug** | `articles/optimal` |
| **Livewire page** | `Filament/Pages/ArticlesOptimal.php` |
| **View** | `resources/views/filament/pages/articles-optimal.blade.php` |
| **Navigation** | SEO Workspace â†’ Articles â†’ **SEO audit** (sort `2`, icon `heroicon-o-magnifying-glass-circle`) |
| **Quyá»n** | `ArticleResource::canViewAny()` |
| **DB** | `omi_seo_ai.articles` (+ join logic `seo_project_tasks`) |

Trang **khÃ´ng** dÃ¹ng React/Vite â€” toÃ n bá»™ UI lÃ  Blade + Alpine.js + Livewire.

### NguyÃªn táº¯c báº£n ghi Laravel vs WordPress

BÃ i trÃªn Laravel lÃ  **báº£n táº¡m** Ä‘á»ƒ Ä‘á»“ng bá»™ / sá»­a chá»¯a SEO. Thao tÃ¡c local (trash, xÃ³a soft-delete, Ä‘á»•i draft) **Ä‘Æ°á»£c phÃ©p trÃªn Laravel**. Outbound sync **khÃ´ng** Ä‘Æ°á»£c xÃ³a / move-to-trash / háº¡ draft bÃ i Ä‘Ã£ tá»“n táº¡i trÃªn WordPress. Bá» bÃ i khá»i audit dÃ¹ng meta `skip_seo_audit`, khÃ´ng demote WP. Chi tiáº¿t outbound: [MAP_SEO_WP.md](MAP_SEO_WP.md).

---

## 2. Kiáº¿n trÃºc UI (2 tab)

Tab chuyá»ƒn **client-side** (Alpine `activeTab`), khÃ´ng gá»i Livewire chá»‰ Ä‘á»ƒ toggle.

```mermaid
flowchart TB
    PAGE["ArticlesOptimal<br/>/seo/articles/optimal"]

    PAGE --> TABS["Tab Bar<br/>Alpine activeTab"]
    TABS --> AUDIT["Tab: SEO Audit"]
    TABS --> REVIEWED["Tab: Reviewed"]

    AUDIT --> FILTERS["Section Filters<br/>domain, language, 6 checkbox"]
    AUDIT --> SCAN["runScan() â†’ hasScanned=true"]
    AUDIT --> RESULTS["Báº£ng káº¿t quáº£ + pagination"]
    AUDIT --> SIDEBAR["Sidebar Content Project<br/>fixed 30% pháº£i"]

    REVIEWED --> STATS["4 stat cards<br/>Today / Week / Month / Total"]
    REVIEWED --> TOOLBAR["Search + Date / Status / Sort<br/>Alpine client-side"]
    REVIEWED --> CARDS["Day cards theo reviewed_at"]
    CARDS --> LIST["Article list items<br/>View + Edit"]
```

| Tab | Key Alpine | Ná»™i dung |
|-----|------------|----------|
| **SEO Audit** | `audit` (máº·c Ä‘á»‹nh) | Bá»™ lá»c, nÃºt QuÃ©t, báº£ng cáº£nh bÃ¡o SEO, bulk assign, sidebar project |
| **Reviewed** | `reviewed` | Dashboard: stat cards, toolbar lá»c client-side, day cards accordion, list bÃ i Ä‘Ã£ duyá»‡t |

Sidebar Content Project **chá»‰ hiá»‡n** khi `activeTab === 'audit'`.

---

## 3. Backend â€” `ArticlesOptimal.php`

### 3.1 State & URL query

CÃ¡c filter SEO Audit Ä‘Æ°á»£c persist qua `#[Url]`:

| Property | URL key | MÃ´ táº£ |
|----------|---------|-------|
| `filterSiteId` | `site` | Lá»c theo `site_id` (pháº¡m vi â€” AND) |
| `selectedScoringRuleKeys` | `rules` | Danh sÃ¡ch rule key tá»« `SeoScoringSettingsService::auditFilterDefinitions()` |
| `filterLowSeoScore` | `low` | Aggregate: Ä‘iá»ƒm SEO `< SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD` (60) |
| `filterTechnicalSeoScore` | `tech` | Aggregate: Ä‘iá»ƒm ká»¹ thuáº­t `< AUDIT_LOW_SCORE_THRESHOLD` |
| `filterLanguage` | `lang` | NgÃ´n ngá»¯ bÃ i viáº¿t (pháº¡m vi â€” AND) |
| `filterPostType` | `post_type` | Post type tá»« union `articles.type` trong scope site (empty = all) |
| `hasScanned` | `scan` | ÄÃ£ báº¥m QuÃ©t Ã­t nháº¥t má»™t láº§n (hoáº·c auto-load máº·c Ä‘á»‹nh khi má»Ÿ trang) |

**Káº¿t quáº£ máº·c Ä‘á»‹nh (keyword review):** `mount()` â†’ `loadDefaultAuditResults()` load bÃ i cÃ³ keyword `review_status` warning/danger qua `SeoAuditKeywordFlagService::resolveResultArticleIds()` â€” khÃ´ng cáº§n báº¥m QuÃ©t.

**UNION fix (quan trá»ng):** Khi user chá»n scoring rules / aggregate rá»“i QuÃ©t, `paginateMergedResults` **khÃ´ng** UNION keyword_review Warning/Danger. Chá»‰ tráº£ bÃ i khá»›p rule SQL Ä‘Ã£ chá»n. `missing_focus_keyword` â‰  keyword_review (bÃ i Ä‘Ã£ cÃ³ keyword canonical nhÆ°ng warning/danger **khÃ´ng** match thiáº¿u keyword).

Canonical thiáº¿u keyword: `SeoAuditScanService::applyMissingFocusKeywordScope` / `hasCanonicalFocusKeyword` â€” `seo_focus_keyword` meta (NULL/empty/whitespace) **hoáº·c** khÃ´ng cÃ³ `keyword_meta` MainArticleId + phrase.

Nguá»“n hiá»ƒn thá»‹: `audit_sources` = `keyword_review` (default) | `seo_rules` (khi chá»n rule).

State khÃ¡c (khÃ´ng URL): `selectedArticleIds`, `sidebarProjectId`, `sidebarCollapsed` (persist Livewire + Alpine `@entangle`, trÃ¡nh remorph reset drawer), `scanState` (`idle\|scanning\|completed\|empty\|failed`), `scanError`, `cachedScanRows`.

### 3.2 Computed properties

| Property | Method | Khi nÃ o cháº¡y |
|----------|--------|--------------|
| `resultsPaginator` | `getResultsPaginator()` | Tab Audit â€” sau `hasScanned=true` |
| `reviewedArticlesGrouped` | `getReviewedArticlesGrouped()` | Má»—i render trang (tab Reviewed) |

### 3.3 Public actions (Livewire)

| Method | MÃ´ táº£ |
|--------|-------|
| `runScan()` | PhÃ¢n tÃ­ch toÃ n bá»™ scope â†’ cache `cachedScanRows`, set `scanState`, try/catch/finally |
| `getScoringRuleFilterDefinitions()` | Checkbox quy táº¯c cháº¥m Ä‘iá»ƒm (enabled + filterable tá»« registry) |
| `getAggregateFilterDefinitions()` | Checkbox Ä‘iá»ƒm tá»•ng há»£p (ngÆ°á»¡ng tá»« registry) |
| `skipSeoAudit($articleId)` | Set `article_meta.skip_seo_audit=1` â€” loáº¡i khá»i audit, **khÃ´ng** Ä‘á»•i status / **khÃ´ng** sync WP; `skipRender()` + toast |
| `skipSelectedSeoAudit($articleIds?)` | Bulk skip (IDs tá»« Alpine); `skipRender()` + toast |
| `assignFromSidebar($articleIds, $data)` | Assign qua sidebar; tráº£ `{project_id, remaining}`; cáº£nh bÃ¡o capacity â‰¤2; `skipRender()` |
| `assignArticleToContentProject` | Assign 1 bÃ i qua `ArticleResource::assignArticlesFromFormData` |
| `assignArticleToSelectedProject` | Assign 1 bÃ i vÃ o `sidebarProjectId` |
| `assignSelectedArticlesToSelectedProject` | Bulk assign `selectedArticleIds` |
| `quickCreateSidebarProject` | Táº¡o project nhanh qua `ArticleResource::quickCreateContentProject` |
| `selectSidebarProject` | Cáº­p nháº­t `sidebarProjectId`; `skipRender()` |

---

## 4. Tab SEO Audit â€” pipeline quÃ©t

### 4.1 Pháº¡m vi query (`baseArticleQuery`)

Chá»‰ láº¥y bÃ i **cáº§n audit**:

```text
articles
  WHERE countsTowardSeoScore()     -- skip_seo_score = false/null
    AND type NOT IN (category, product_category)
    AND status != trash
    AND (is_reviewed = false OR is_reviewed IS NULL)
    AND NOT EXISTS (seo_project_tasks WHERE article_id = articles.id)
    AND NOT EXISTS (article_meta WHERE meta_key = skip_seo_audit AND meta_value = 1)
  [+ filter site_id / language / post_type náº¿u cÃ³]
  ORDER BY updated_at DESC
```

Sau khi load collection, **loáº¡i thÃªm** trong PHP (khÃ´ng náº±m trong SQL):

- `is_reviewed = true`
- `ArticleResource::articleIsInContentProject($article)` â€” Ä‘Ã£ cÃ³ task trong Content Project

### 4.2 Query cache SEO â€” khÃ´ng analyze HTML trong request (`SeoAuditScanService`)

Kiáº¿n trÃºc **cache + queue** (2026-07):

| Nguá»“n cache | Cá»™t/meta |
|-------------|----------|
| Violations | `article_meta.seo_rule_violations` (JSON array `rule_key`) |
| Score | `articles.seo_score` |
| Tráº¡ng thÃ¡i queue | `article_meta.seo_scoring_status` (`pending` / `processing` / `completed` / `failed`) |
| Fingerprint Ä‘á»•i ná»™i dung | `article_meta.seo_scoring_fingerprint` |

| Service | Vai trÃ² |
|---------|---------|
| `SeoArticleScoringQueueService` | Dispatch `AnalyzeArticleSeoJob`, backfill domain, `domainProgress()` |
| `AnalyzeArticleSeoJob` | Gá»i `SeoAnalyzerService::analyze()` â†’ persist violations + score (unique per article) |
| `SeoAuditScanService` | `buildFilteredQuery()` + `paginateResults()` â€” filter SQL (`JSON_CONTAINS`, `seo_score`) |
| `SeoRuleViolationsResolver` | Äá»c cache violations (format má»›i + legacy) |
| `SeoAuditRuleMatcher` | OR semantics cho checkbox; scope AND qua `baseArticleQuery()` |
| `SeoScoringRulesRegistry` | Source of truth rule keys + audit filter definitions |

**KhÃ´ng cÃ²n** `scanWithHtmlAnalysis()` / `SeoEngineService::analyzeHtml()` trong request Audit.

Trigger populate cache:

- WP sync (`SyncDomainContentService::importSingleSyncItem`) â†’ `dispatchIfSyncItemChanged()`
- Editor save (`ArticleEditorPersistService`) â†’ `dispatchForArticle(force: true)`
- Domain actions (`GeneralDomain`) â†’ queue missing / retry failed / requeue all

Output má»—i row (map tá»« DB, khÃ´ng parse body):

| Key | MÃ´ táº£ |
|-----|-------|
| `id`, `title`, `domain` | ThÃ´ng tin cÆ¡ báº£n |
| `permalink` | Tá»« `article_metas.meta_key = wp_permalink` |
| `edit_url` | `ArticleResource::getUrl('edit', â€¦)` |
| `score` | Tá»« `articles.seo_score` + `SeoScoringCalculator` |
| `matched_rule_keys` / `reason_labels` | Tá»« cache `seo_rule_violations` (active rules only) |

### 4.3 Logic filter (`SeoAuditRuleMatcher`)

- **Pháº¡m vi** (`site`, `lang`): AND qua SQL `baseArticleQuery()`.
- **KhÃ´ng chá»n rule/aggregate nÃ o** â†’ má»i bÃ i trong scope hiá»ƒn thá»‹.
- **Quy táº¯c cháº¥m Ä‘iá»ƒm** (`selectedScoringRuleKeys`): match **ANY** â€” canonical rule key tá»« `SeoScoringRulesRegistry::activeViolations()`.
- **Aggregate** (`low`): so sÃ¡nh `articles.seo_score` vá»›i `AUDIT_LOW_SCORE_THRESHOLD` (60). Checkbox **technical score Ä‘Ã£ áº©n** â€” há»‡ thá»‘ng chá»‰ cache má»™t `seo_score` tá»•ng.
- Rule **disabled** táº¡i `/settings/scoring`: khÃ´ng filter, khÃ´ng badge, khÃ´ng trá»« Ä‘iá»ƒm runtime; violation cÅ© trong DB bá»‹ bá» qua.

Checkbox UI sinh tá»« `SeoScoringSettingsService::auditFilterDefinitions()` â€” label threshold (vd. Ä‘á»™ dÃ i bÃ i) láº¥y tá»« `SeoPromptSettingsService`, khÃ´ng hard-code 600.

### 4.4 Scan state & pagination

- `runScan()` chá»‰ validate filter + Ä‘áº¿m cache status; **khÃ´ng** analyze HTML.
- `getResultsPaginator()` â†’ `SeoAuditScanService::paginateResults()` â€” pagination SQL.
- `scanState`: `idle` â†’ `scanning` â†’ `completed` \| `empty` \| `failed`; `scanNotice` khi cÃ²n bÃ i pending queue.
- BÃ i chÆ°a cÃ³ cache: empty state + nÃºt Â«Cháº¥m SEO cÃ²n thiáº¿uÂ» (domain filter) hoáº·c action trÃªn GeneralDomain.
- Äá»•i filter â†’ `invalidateScanResults()` (pháº£i quÃ©t láº¡i).

### 4.5 Báº£ng káº¿t quáº£ & hÃ nh Ä‘á»™ng

| Cá»™t | Ná»™i dung |
|-----|----------|
| Checkbox | Bulk select (Alpine `selectedArticleIds` â†” Livewire entangle) |
| Title | Link permalink WP (náº¿u cÃ³) |
| Domain | `site.domain` |
| Warnings | Danh sÃ¡ch `reason_labels` |
| Score | MÃ u theo ngÆ°á»¡ng: `<50` Ä‘á», `50â€“70` vÃ ng, `>70` xanh |
| Actions | Edit Â· Skip audit Â· Assign project |

**Skip audit:** Alpine `hideRows()` áº©n row ngay â†’ `$wire.skipSeoAudit` / `skipSelectedSeoAudit` ngáº§m (`skipRender()`). Toast Filament khi xong. KhÃ´ng overlay cháº·n trang.

**Assign project:** Icon folder má»Ÿ sidebar; `submitSidebarAssign()` áº©n row + xÃ³a focus keyword â†’ `$wire.assignFromSidebar` ngáº§m. NÃºt **PhÃ¢n bÃ i** disable khi chÆ°a chá»n bÃ i (`canSubmitAssign()`). Delegate `ArticleResource::assignArticlesFromFormData` â€” xem [MAP_SEO_PROJECTS.md](MAP_SEO_PROJECTS.md).

**Project capacity:** Sau assign, `remaining â‰¤ 2` â†’ toast cáº£nh bÃ¡o (`articles_optimal.project_capacity_*`); `remaining = 0` â†’ Alpine `hideFullProject()` xÃ³a option khá»i select. Options ban Ä‘áº§u tá»« `ArticleResource::contentProjectOptions()` (chá»‰ project `canRegisterMoreTasks()`).

---

## 5. Sidebar Content Project

Fixed panel pháº£i (~30% width). `sidebarCollapsed` sync Livewire + Alpine â€” drawer khÃ´ng bá»‹ reset sau skip/assign.

| ThÃ nh pháº§n | Nguá»“n dá»¯ liá»‡u |
|------------|---------------|
| Dropdown project | `getContentProjectOptions()` â†’ `ArticleResource::contentProjectOptions($siteId)`; label cÃ³ `cÃ²n N` |
| NÃºt táº¡o nhanh | Modal â†’ `quickCreateSidebarProject` |
| Form assign | Loáº¡i bÃ i, rewrite mode, focus keyword (khi thiáº¿u), nÃºt PhÃ¢n bÃ i |

Assign: chá»n bÃ i (checkbox hoáº·c icon folder) â†’ má»Ÿ sidebar â†’ chá»n project â†’ PhÃ¢n bÃ i. KhÃ´ng cÃ²n block Â«BÃ i viáº¿t trong dá»± Ã¡nÂ» dÆ°á»›i form.

**i18n:** Chuá»—i sidebar dÃ¹ng `lang/*/filament.php` â†’ `articles_optimal.*` (UTF-8).

Skip/assign dÃ¹ng `skipRender()` â€” khÃ´ng remorph DOM, sidebar giá»¯ tráº¡ng thÃ¡i má»Ÿ.

---

## 6. Tab Reviewed â€” dashboard bÃ i Ä‘Ã£ duyá»‡t

### 6.1 Query (`getReviewedArticlesGrouped`) â€” khÃ´ng Ä‘á»•i

```text
articles
  WHERE is_reviewed = true
    AND reviewed_at IS NOT NULL
    AND type NOT IN (category, product_category)
    AND status != trash
  [+ scope site theo SeoAccessControl náº¿u non-admin]
  ORDER BY reviewed_at DESC
```

NhÃ³m theo `reviewed_at->toDateString()` (Y-m-d). Payload má»—i group:

| Field | Nguá»“n |
|-------|--------|
| `date`, `date_label` | NgÃ y nhÃ³m |
| `count` | Sá»‘ bÃ i trong ngÃ y |
| `articles[]` | `id`, `title`, `reviewed_time` (H:i), `edit_url` |

Blade enrich thÃªm (chá»‰ UI, khÃ´ng Ä‘á»•i API PHP):

| Field | CÃ¡ch tÃ­nh |
|-------|-----------|
| `first_review` | `reviewed_time` cá»§a bÃ i duyá»‡t sá»›m nháº¥t trong ngÃ y |
| `last_review` | `reviewed_time` cá»§a bÃ i duyá»‡t muá»™n nháº¥t trong ngÃ y |
| `is_today` | `date === today` (server timezone) |

`reviewedUiContext` (JSON Alpine): `today`, `weekStart`, `weekEnd`, `monthStart`, `monthEnd` â€” dÃ¹ng cho stat cards vÃ  date filter.

### 6.2 Layout UI (Ahrefs/Semrush-style)

| Khá»‘i | MÃ´ táº£ |
|------|--------|
| **Stat cards** (4) | Today / This Week / This Month / Total Reviewed â€” Ä‘áº¿m client-side tá»« `reviewedGroups` |
| **Toolbar** | Search (`reviewedSearch`), Date filter, Status (Reviewed), Sort (newest/oldest) |
| **Day cards** | Bo gÃ³c 12px, badge sá»‘ bÃ i, meta First/Last Review, chevron + `x-collapse` |
| **Article list** | Icon file, title, dot xanh + Â«ReviewedÂ» + giá»; nÃºt View (tab má»›i) + Edit |

Responsive: stats 4â†’2â†’1 cá»™t; toolbar stack trÃªn mobile.

### 6.3 Alpine state (tab Reviewed)

| Key / method | Vai trÃ² |
|--------------|---------|
| `reviewedGroups` | Copy JSON tá»« `reviewedGroupsEnriched` |
| `reviewedSearch` | Lá»c title â€” **khÃ´ng** gá»i Livewire |
| `reviewedDateFilter` | `all` \| `today` \| `week` \| `month` |
| `reviewedStatus` | `reviewed` (readonly select, chuáº©n bá»‹ má»Ÿ rá»™ng) |
| `reviewedSort` | `newest` \| `oldest` â€” sáº¯p xáº¿p day groups |
| `expandedDates` | Máº·c Ä‘á»‹nh chá»‰ ngÃ y má»›i nháº¥t; toggle `toggleDate()` |
| `filteredReviewedGroups()` | Search + date filter + sort â€” tráº£ groups Ä‘á»ƒ `x-for` |
| `reviewedStatToday/Week/Month/Total()` | Äáº¿m bÃ i theo khoáº£ng ngÃ y |

Tab Reviewed **khÃ´ng** lá»c domain/ngÃ´n ngá»¯ á»Ÿ backend (chá»‰ scope quyá»n tenant). Má»i filter/search cháº¡y **client-side** trÃªn dá»¯ liá»‡u Ä‘Ã£ load.

### 6.4 Style

Inline CSS class prefix `reviewed-*` trong `articles-optimal.blade.php`: ná»n tráº¯ng, border `#E5E7EB`, radius 12px, shadow nháº¹, hover `#F9FAFB`, gap section 24px.

---

## 7. VÃ²ng Ä‘á»i Â«ÄÃ£ duyá»‡tÂ» (`is_reviewed`)

| Cá»™t | Migration | Cast |
|-----|-----------|------|
| `is_reviewed` | `2026_06_03_090000_add_review_fields_to_articles_table` | `boolean` |
| `reviewed_at` | cÃ¹ng migration | `datetime` |

| HÃ nh Ä‘á»™ng | NÆ¡i gá»i | Káº¿t quáº£ |
|-----------|---------|---------|
| Duyá»‡t bÃ i | `ArticleResource::markArticleReviewed()` | `is_reviewed=true`, `reviewed_at=now()`, xÃ³a local media |
| Bá» duyá»‡t | `ArticleResource::markArticleUnreviewed()` | `is_reviewed=false`, `reviewed_at=null` |
| Staff submit (content manager) | `ArticleResource::submitStaffEditingComplete()` â†’ `SeoProjectApprovalService` | Flow project â€” khÃ´ng set trá»±c tiáº¿p `is_reviewed` trÃªn audit page |
| Editor UI | `EditArticle` + `publish-sidebar.blade.php` | NÃºt review theo role |

BÃ i Ä‘Ã£ duyá»‡t **biáº¿n máº¥t** khá»i tab SEO Audit (cáº£ SQL filter láº«n PHP skip).

---

## 8. PhÃ¢n quyá»n & tenant scope

| Layer | Logic |
|-------|-------|
| Truy cáº­p trang | `ArticleResource::canViewAny()` |
| Site filter options | `Site::query()` â€” max 5 domain; scope `user_id` náº¿u `SeoAccessControl::shouldScopeToAccountOwner()` |
| `accessibleArticleQuery()` | `whereIn(site_id, â€¦)` cÃ¹ng táº­p site accessible |
| Assign / skip audit | `findAccessibleArticle()` qua `accessibleArticleQuery()` |

Chi tiáº¿t RBAC: [MAP_SEO_TEAM.md](MAP_SEO_TEAM.md).

---

## 9. Frontend stack (khÃ´ng React)

| Layer | File / cÃ´ng nghá»‡ |
|-------|------------------|
| View chÃ­nh | `articles-optimal.blade.php` |
| Tab SEO Audit | Inline CSS `articles-optimal-tabs-bar` (tone Media Library) |
| Tab Reviewed dashboard | Inline CSS `reviewed-*` (stat cards, toolbar, day cards, list items) |
| Tab toggle | Alpine `activeTab` |
| Reviewed filter/search | Alpine only â€” `filteredReviewedGroups()`, khÃ´ng Livewire |
| Checkbox bulk | Alpine `removedIds` + `@entangle('selectedArticleIds')` (khÃ´ng `.live`) |
| Sidebar assign | Alpine `hideRows`, `canSubmitAssign`, `hideFullProject`; `@entangle('sidebarCollapsed')` |
| Modals quick create | Alpine `quickCreateOpen` |
| Loading | Chá»‰ `runScan` / `queueMissingScoring` dÃ¹ng `wire:loading`; skip/assign **khÃ´ng** overlay toÃ n trang |
| i18n | `lang/{en,vi}/filament.php` â†’ key `articles_optimal.*` |

---

## 10. Báº£n Ä‘á»“ file

| Vai trÃ² | ÄÆ°á»ng dáº«n |
|---------|-----------|
| Page class | `app/Addons/SeoContentAi/Filament/Pages/ArticlesOptimal.php` |
| Blade | `app/Addons/SeoContentAi/resources/views/filament/pages/articles-optimal.blade.php` |
| Model | `app/Addons/SeoContentAi/Models/SeoArticle.php` |
| Assign / review helpers | `app/Addons/SeoContentAi/Filament/Resources/ArticleResource.php` |
| SEO engine | `app/Services/SeoEngineService.php` |
| Quality assessment | `app/Addons/SeoContentAi/Services/SeoArticleQualityAssessmentService.php` |
| Audit scan strategies | `app/Addons/SeoContentAi/Services/SeoAuditScanService.php` |
| Keyword-flag merge / UNION gate | `app/Addons/SeoContentAi/Services/SeoAuditKeywordFlagService.php` (`resolveResultArticleIds`) |
| Agent read `/audit-list` | `Services/SeoAudit/Agent/SeoAuditAgentReadService.php` + capability `seo_audit.list` (Gateway READ; reuse Web query) |
| Agent skill / presenter | `Services/AgentWorkspace/Skills/SeoAuditSkills.php`, `Execution/Presentation/AuditListPresenter.php`, `Execution/Rendering/SeoAuditResultRenderer.php` |
| SEO scoring queue | `app/Addons/SeoContentAi/Services/SeoArticleScoringQueueService.php` |
| Analyze job | `app/Addons/SeoContentAi/Jobs/AnalyzeArticleSeoJob.php` |
| Scoring status meta | `app/Addons/SeoContentAi/Support/SeoScoringStatus.php` |
| Audit filter matcher | `app/Addons/SeoContentAi/Services/SeoAuditRuleMatcher.php` |
| Scoring registry / filters | `app/Addons/SeoContentAi/Support/SeoScoringRulesRegistry.php` |
| Analyzer | `app/Addons/SeoContentAi/Services/SeoAnalyzerService.php` |
| Skip audit meta | `ArticleResource::META_SKIP_SEO_AUDIT` (`skip_seo_audit`) â€” set bá»Ÿi `ArticlesOptimal::skipSeoAudit` |
| Migration review fields | `app/Addons/SeoContentAi/database/migrations/2026_06_03_090000_add_review_fields_to_articles_table.php` |
| Translations | `app/Addons/SeoContentAi/lang/{en,vi}/filament.php` â†’ `articles_optimal` |
| Tests | `SeoAuditScoringIntegrationTest.php`, `SeoAuditScanServiceTest.php`, `SeoAuditCacheArchitectureTest.php`, `SeoAuditMissingFocusKeywordAuditFilterTest.php` |

---

## 11. HÆ°á»›ng dáº«n prompt â€” SEO Audit

### Má»Ÿ rá»™ng filter / cáº£nh bÃ¡o má»›i

```
Page: Filament/Pages/ArticlesOptimal.php
View: resources/views/filament/pages/articles-optimal.blade.php
ThÃªm checkbox: báº­t `filterable` + metadata trong `SeoScoringRulesRegistry::ruleCatalog()` â€” UI tá»± sinh qua `auditFilterDefinitions()`. Match qua `SeoAuditRuleMatcher` + canonical rule key, khÃ´ng hard-code trong Blade.
PhÃ¢n tÃ­ch: mapArticleRow() â†’ SeoEngineService::analyzeHtml()
i18n: lang/{en,vi}/filament.php articles_optimal.*
```

### ThÃªm cá»™t hoáº·c action trÃªn báº£ng Audit

```
mapArticleRow() tráº£ thÃªm key â†’ foreach $paginator trong articles-optimal.blade.php
Action server: public method trÃªn ArticlesOptimal + authorize qua accessibleArticleQuery()
Skip audit: skipSeoAudit() â†’ article_meta.skip_seo_audit=1 (khÃ´ng sync WP)
```

### Tab Reviewed â€” UI / filter client-side

```
Data: getReviewedArticlesGrouped() â€” khÃ´ng Ä‘á»•i signature
Blade enrich: first_review, last_review, is_today + reviewedUiContext
Alpine: reviewedSearch, reviewedDateFilter, reviewedSort, filteredReviewedGroups()
Stat cards: reviewedStatToday/Week/Month/Total()
Edit link: article.edit_url (View = tab má»›i, Edit = cÃ¹ng tab)
i18n: articles_optimal.reviewed_* trong lang/{en,vi}/filament.php
```

### LiÃªn káº¿t vá»›i Content Project

```
Assign: ArticleResource::assignArticlesFromFormData()
Sidebar options: ArticleResource::contentProjectOptions($siteId)
BÃ i Ä‘Ã£ assign: articleIsInContentProject() â†’ loáº¡i khá»i audit scan
Chi tiáº¿t project: docs/MAP_SEO_PROJECTS.md
```

---

## 12. Giá»›i háº¡n & lÆ°u Ã½ váº­n hÃ nh

| Chá»§ Ä‘á» | Chi tiáº¿t |
|--------|----------|
| **Performance** | Audit chá»‰ query cache DB; scoring cháº¡y qua `AnalyzeArticleSeoJob` (sync/save/domain actions) |
| **TrÃ¹ng filter Ä‘iá»ƒm** | `filterLowSeoScore` vÃ  `filterTechnicalSeoScore` cÃ¹ng Ä‘iá»u kiá»‡n `< 60` |
| **Category** | Loáº¡i `category`, `product_category` khá»i cáº£ Audit vÃ  Reviewed |
| **ÄÃ£ trong project** | CÃ³ row `seo_project_tasks` â†’ loáº¡i khá»i SQL audit query |
| **Tests** | `SeoAuditScoringIntegrationTest` â€” registry filters, disabled rules, matcher ANY/aggregate |
