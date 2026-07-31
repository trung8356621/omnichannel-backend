# SeoContentAi — Domain Management

[← Quay lại FEATURE_MAP_FULL.md](FEATURE_MAP_FULL.md) · [SUPER_MAP_INDEX.md](SUPER_MAP_INDEX.md)

**Liên quan:** [WordPress sync](MAP_SEO_WP.md) · [Settings & Prompt](MAP_SEO_SETTINGS.md) · [Content Projects](MAP_SEO_PROJECTS.md)

> **Ngày khảo sát:** 06/07/2026
> Domain là menu quản lý site chính trong Filament. File Resource: `Filament/Resources/DomainResource.php` (slug: `domains`, model: `Site`, connection: `mysql`).

---

## 1. Routes Filament

```
/seo/{connection_hash}/domains                    → ListDomains (index)
/seo/{connection_hash}/domains/create             → CreateDomain
/seo/{connection_hash}/domains/{record}/edit      → EditDomain
/seo/{connection_hash}/domains/{record}/general   → GeneralDomain (overview)
/seo/{connection_hash}/domains/{record}/mcp       → ViewDomainMcp (MCP HTML docs — manager)
/seo/{connection_hash}/domains/{record}/internal-links → ListDomainInternalLinks
/seo/{connection_hash}/domains/settings           → DomainGlobalCtaSettings
```

---

## 2. Domain Filament Pages (10 pages)

| Page | Slug | Mô tả |
|------|------|-------|
| **ListDomains** | `domains` | Danh sách domain + header actions: Global CTA settings, Add domain |
| **CreateDomain** | `create` | Tạo domain mới với SEO defaults (tone, short_description, CTA) |
| **EditDomain** | `{record}/edit` | Official **Site MCP Knowledge Profile** form (tone/CTA/links/short description) + header **Generate/Regenerate draft** / **Show draft panel**. Draft = fixed right drawer (`site_meta.site_mcp_draft` only; never overwrite official). Views: `edit-domain.blade.php`, `partials/site-mcp-draft-panel.blade.php` |
| **GeneralDomain** | `{record}/general` | **Overview chính** (site-specific): API tokens, score distribution, sync stats, top keywords/links, official Site MCP summary + link **Chỉnh sửa / Generate draft** → Edit. Header: nút **MCP Markdown** → `ViewDomainMcp` (Agent docs — khác Site MCP). **Không** embed draft generator / global MCP catalog tại đây |
| **ViewDomainMcp** | `{record}/mcp` | Readonly **Developer MCP Reference** (Agent `CanonicalCapabilityRegistry` docs) — **không** phải Site MCP Knowledge Profile. Domain URL = navigation shell only. Manager-only |
| **ListDomainInternalLinks** | `{record}/internal-links` | Internal links với tab keywords/links |
| **RedirectDomainInfoToEdit** | `{record}/info` | Redirect `/info` → `/edit` |
| **DomainGlobalCtaSettings** | `domains/settings` | Global CTA settings (working_hours, zalo...) — lưu WpOption |
| **ArticleDomainMismatch** | (no nav) | Cảnh báo khi article thuộc domain ≠ domain hiện tại, cho phép switch |
| **AllDomainsListWidget** | widget | Score distribution bars per domain, worst article |
| **AllDomainsProjectsWidget** | widget | Content projects progress per domain |
| **AllDomainsTeamWidget** | widget | Team productivity (articles optimized per content manager) |

---

## 3. Domain Services (14 files)

### 3.1 Technical SEO & Config

| # | Service | File | Chức năng chính |
|---|---------|------|-----------------|
| 1 | **DomainOverviewService** | `Services/DomainOverviewService.php` | Tổng quan domain: API tokens (password-protected), phân bố điểm SEO (poor/fair/good/excellent), sync stats (articles/products/categories), top keywords, top links, technical SEO summary (short description, CTA count, link count) |
| 2 | **AllDomainsDashboardService** | `Services/AllDomainsDashboardService.php` | Dashboard tổng hợp tất cả domain: health overview, content project progress, team productivity |
| 3 | **SeoMainDomainService** | `Services/SeoMainDomainService.php` | Quản lý "miền chính" per user (meta key `seo_is_main`). Set, unset, resolve, deduplicate primary sites |
| 4 | **ClearDomainArticlesService** | `Services/ClearDomainArticlesService.php` | Xóa vĩnh viễn toàn bộ SeoArticle + SeoPromptResultLink của domain |
| 5 | **SiteDomainPromptContextService** | `Services/SiteDomainPromptContextService.php` | **Official Site MCP Knowledge Profile** (UI: Edit Domain): tone, short_description (≤300 từ), CTA slots, link list, cta_intro. Meta `seo_domain_prompt_context`. Prompt vars `promptVariablesForSite()` |
| 5b | **SiteMcp\*** (draft) | `Services/SiteMcp/` | **Site MCP draft** (Knowledge Profile, không phải Agent MCP): `SiteMcpDiscovery` ưu tiên live WP `product_cat` → staging verified → không heuristic; `SiteMcpProductCatIdentity` (canonical term + parent_term_id kể cả `0`); `SiteMcpProductCatLiveSource` (GET taxonomy terms / refresh `/terms`); `SiteMcpGenerator` (`news_manual` / `production_catalog` / `ecommerce_catalog`) — Main Topics = `product_cat` + `term_id>0` + `parent_term_id===0` only; `SiteMcpDraft` / `Preview` / `KeywordExtractor` / `OfficialGuard` / `ContactDiscovery`. Counts: `product_cat_total` / `root_product_cat` / `child_product_cat`; availability `available\|unavailable\|incomplete`; warning `PRODUCT_CATEGORY_TAXONOMY_CAPABILITY_MISSING` vs `ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE` |
| 6 | **DomainCtaEditorService** | `Services/DomainCtaEditorService.php` | Format CTA list cho article editor (href, plain_text, can_insert) |
| 7 | **DomainLinkListEditorService** | `Services/DomainLinkListEditorService.php` | Cung cấp link list cho article editor kèm article_count (số bài đã chèn anchor). Có `forArticle()` lọc theo nội dung bài |
| 8 | **SeoDomainCtaGlobalSettingsService** | `Services/SeoDomainCtaGlobalSettingsService.php` | Global CTA settings (WpOption `seo_domain_cta_global_settings`): `default_cta_intro`, `global_cta` (working_hours, address, facebook, zalo) |

### 3.2 Domain Sync & Resync

| # | Service | File | Chức năng chính |
|---|---------|------|-----------------|
| 9 | **IncrementalDomainSyncRunner** | `Services/IncrementalDomainSyncRunner.php` | Chạy incremental sync theo chunk, đọc/ghi cache progress, gửi Filament notification |
| 10 | **MetadataDomainSyncRunner** | `Services/MetadataDomainSyncRunner.php` | Chạy metadata resync theo chunk (ngôn ngữ, Polylang, SEO meta) |
| 11 | **KeywordDomainResyncService** | `Services/KeywordDomainResyncService.php` | Reset & resync keywords: xóa CTA-blacklisted, xóa orphan linked keywords, rescan articles → link maps, focus keyword sync |
| 12 | **WordPressPluginDomainsOverviewService** | `Services/WordPressPluginDomainsOverviewService.php` | Kiểm tra version WordPress plugin (omi-seo-ai-bridge) trên từng domain |
| 13 | **SyncDomainContentService** | `Services/SyncDomainContentService.php` | Đồng bộ nội dung từ WordPress: full sync, prepareIncrementalSync, processIncrementalChunk, prepareMetadataResync, resetAndFullSync, importPushedItems. Taxonomy: persist `wp_parent_id` kể cả `"0"` (không xóa khi parent=0 — Site MCP fail-closed) + `wp_term_count` |
| 14 | **DomainLinkListKeywordSyncService** | `Services/DomainLinkListKeywordSyncService.php` | Đồng bộ link list → keywords table. Upsert/Remove link trong domain context |

---

## 4. Domain Settings (CTA, Link List, Short Description, Tone)

### 4.1 3-layer Architecture

| Layer | Service | Storage | Fields |
|-------|---------|--------|--------|
| **Global** | `SeoDomainCtaGlobalSettingsService` | WpOption `seo_domain_cta_global_settings` | `default_cta_intro`, `global_cta` (working_hours, address, facebook, zalo) |
| **Domain (official)** | `SiteDomainPromptContextService` | Site meta `seo_domain_prompt_context` | `tone`, `short_description`, `cta_intro`, `cta[]`, `links[]` |
| **Domain (draft)** | `SiteMcp\SiteMcpDraft` | Site meta `site_mcp_draft` | Knowledge Profile draft JSON (topics/pages/generation); AI context = topics only (no URLs); never auto-apply |
| **Form** | `PersistsDomainPromptContext` trait | Auto-save khi form submit + sync link list → keywords |

### 4.2 DomainTechnicalSeoForm (4 sections)

1. **Domain Settings**: tone of voice (select từ `SeoPromptSettingsService`)
2. **Short Description**: textarea, ≤300 từ, word counter
3. **CTA Section**: phone (3 slots), email (3 slots), cta_intro (textarea), CTA repeater (type + value)
4. **Link List**: repeater (keyword + URL)

### 4.3 Form Persistence Flow

```
CreateDomain/EditDomain
  → PersistsDomainPromptContext::queuePromptContextFromFormState()
  → PersistsDomainPromptContext::persistPendingDomainPromptContext()
    → SiteDomainPromptContextService::saveForSite()
    → DomainLinkListKeywordSyncService::syncLinks() (đồng bộ link list → keywords)
```

---

## 5. Domain Sync Cache (Support, 4 files)

| File | Class | Mục đích |
|------|-------|----------|
| `Support/DomainSyncManifestComparator.php` | Comparator | So sánh WordPress manifest vs local articles → xác định refs cần fetch (new/update/skip) |
| `Support/IncrementalDomainSyncCache.php` | Cache | State machine: running/completed/failed/resumable, STALE_AFTER_SECONDS=120 |
| `Support/MetadataDomainSyncCache.php` | Cache | Tương tự IncrementalDomainSyncCache cho metadata sync |
| `Support/KeywordDomainResyncCache.php` | Cache | State machine + orphan detection (ORPHAN_AFTER_SECONDS=180) |

---

## 6. Domain Functions & Queue Dispatch

| Chức năng | Queue Job | Service gốc |
|-----------|-----------|-------------|
| **Incremental Sync** (đồng bộ bài viết mới/cập nhật từ WP) | `RunIncrementalDomainSyncJob` (queue `seo`, unique 2h) | → `IncrementalDomainSyncRunner::run()` |
| **Refresh Article Metadata** (đồng bộ meta WP: ngôn ngữ, Polylang, SEO) | `RunMetadataDomainSyncJob` (queue `seo`, unique 2h) | → `MetadataDomainSyncRunner::run()` |
| **Rescrape Keywords** (reset + rescrape keywords từ articles) | `RunKeywordDomainResyncJob` (queue) | → `KeywordDomainResyncService::resetAndResync()` |
| **SEO scoring (backfill)** | `AnalyzeArticleSeoJob` (unique per article) | → `SeoArticleScoringQueueService` |
| **Audit Link Health** (kiểm tra HTTP status của link maps) | `AuditLinkStatusJob` (queue per link, chunk per domain) | → `LinkMapStatusAuditService::queueDomainAudit()` |
| **Test Sync** (debug) | Đồng bộ, không queue | → `SyncDomainContentService::performDebugSync()` |

---

## 7. Domain File Index

### Filament (16 files)

| File | Loại |
|------|------|
| `Filament/Resources/DomainResource.php` | Resource (Site model) |
| `Filament/Resources/DomainResource/Pages/ListDomains.php` | Page |
| `Filament/Resources/DomainResource/Pages/CreateDomain.php` | Page |
| `Filament/Resources/DomainResource/Pages/EditDomain.php` | Page — official Site MCP + draft drawer |
| `resources/views/.../edit-domain.blade.php` + `partials/site-mcp-draft-panel.blade.php` | Edit layout + fixed draft drawer |
| `Filament/Resources/DomainResource/Pages/GeneralDomain.php` | Page (overview; link Edit for Site MCP draft; nút MCP Markdown → ViewDomainMcp) |
| `Filament/Resources/DomainResource/Pages/ViewDomainMcp.php` | Page — Developer/Agent MCP docs (`/{record}/mcp`) — khác Site MCP |
| `Services/SiteMcp/{SiteMcpDraft,Discovery,Generator,Preview,KeywordExtractor,OfficialGuard,ContactDiscovery,ProductCatIdentity,ProductCatLiveSource}.php` | Site MCP Knowledge Profile draft pipeline (verified product_cat roots → Main Topics) |
| `Filament/Resources/DomainResource/Pages/ListDomainInternalLinks.php` | Page |
| `Filament/Resources/DomainResource/Pages/RedirectDomainInfoToEdit.php` | Page |
| `Filament/Resources/DomainResource/Pages/ArticleDomainMismatch.php` | Page |
| `Filament/Resources/DomainResource/Concerns/PersistsSeoDomainMetas.php` | Trait |
| `Filament/Resources/DomainResource/Concerns/PersistsDomainPromptContext.php` | Trait |
| `Filament/Resources/DomainResource/Forms/DomainTechnicalSeoForm.php` | Form Schema |
| `Filament/Pages/DomainGlobalCtaSettings.php` | Page |
| `Filament/Widgets/AllDomainsListWidget.php` | Widget |
| `Filament/Widgets/AllDomainsProjectsWidget.php` | Widget |
| `Filament/Widgets/AllDomainsTeamWidget.php` | Widget |
| `Filament/Concerns/InteractsWithSeoAllDomainsDashboard.php` | Trait |

### Services (14 files)

| File |
|------|
| `Services/DomainOverviewService.php` |
| `Services/AllDomainsDashboardService.php` |
| `Services/SeoMainDomainService.php` |
| `Services/ClearDomainArticlesService.php` |
| `Services/SiteDomainPromptContextService.php` |
| `Services/DomainCtaEditorService.php` |
| `Services/DomainLinkListEditorService.php` |
| `Services/SeoDomainCtaGlobalSettingsService.php` |
| `Services/IncrementalDomainSyncRunner.php` |
| `Services/MetadataDomainSyncRunner.php` |
| `Services/KeywordDomainResyncService.php` |
| `Services/WordPressPluginDomainsOverviewService.php` |
| `Services/SyncDomainContentService.php` |
| `Services/DomainLinkListKeywordSyncService.php` |

### Support (4 files)

| File |
|------|
| `Support/DomainSyncManifestComparator.php` |
| `Support/IncrementalDomainSyncCache.php` |
| `Support/MetadataDomainSyncCache.php` |
| `Support/KeywordDomainResyncCache.php` |

### Jobs (3 files)

| File |
|------|
| `Jobs/RunIncrementalDomainSyncJob.php` |
| `Jobs/RunMetadataDomainSyncJob.php` |
| `Jobs/RunKeywordDomainResyncJob.php` |

---

## Hướng dẫn prompt

```
Resource: Filament/Resources/DomainResource.php
Overview page: Filament/Resources/DomainResource/Pages/GeneralDomain.php
Official Site MCP: Services/SiteDomainPromptContextService.php + Forms/DomainTechnicalSeoForm.php + EditDomain
Site MCP draft: Services/SiteMcp/* → site_meta.site_mcp_draft (EditDomain drawer; live/staging product_cat parent=0 → Main Topics)
Developer/Agent MCP docs: ViewDomainMcp (CanonicalCapabilityRegistry) — not Site MCP
Global CTA: Filament/Pages/DomainGlobalCtaSettings.php + Services/SeoDomainCtaGlobalSettingsService.php
Dashboard widgets: Filament/Widgets/AllDomains{List,Projects,Team}Widget.php
Sync core: Services/SyncDomainContentService.php
```

