> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_MCP_AND_DOMAINS.md
> Purpose: implementation history only
# SeoContentAi â€” Domain Management

[â† Quay láº¡i FEATURE_MAP_FULL.md](FEATURE_MAP_FULL.md) Â· [SUPER_MAP_INDEX.md](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [WordPress sync](MAP_SEO_WP.md) Â· [Settings & Prompt](MAP_SEO_SETTINGS.md) Â· [Content Projects](MAP_SEO_PROJECTS.md)

> **NgÃ y kháº£o sÃ¡t:** 06/07/2026
> Domain lÃ  menu quáº£n lÃ½ site chÃ­nh trong Filament. File Resource: `Filament/Resources/DomainResource.php` (slug: `domains`, model: `Site`, connection: `mysql`).

---

## 1. Routes Filament

```
/seo/{connection_hash}/domains                    â†’ ListDomains (index)
/seo/{connection_hash}/domains/create             â†’ CreateDomain
/seo/{connection_hash}/domains/{record}/edit      â†’ EditDomain
/seo/{connection_hash}/domains/{record}/general   â†’ GeneralDomain (overview)
/seo/{connection_hash}/domains/{record}/mcp       â†’ ViewDomainMcp (MCP HTML docs â€” manager)
/seo/{connection_hash}/domains/{record}/internal-links â†’ ListDomainInternalLinks
/seo/{connection_hash}/domains/settings           â†’ DomainGlobalCtaSettings
```

---

## 2. Domain Filament Pages (10 pages)

| Page | Slug | MÃ´ táº£ |
|------|------|-------|
| **ListDomains** | `domains` | Danh sÃ¡ch domain + header actions: Global CTA settings, Add domain |
| **CreateDomain** | `create` | Táº¡o domain má»›i vá»›i SEO defaults (tone, short_description, CTA) |
| **EditDomain** | `{record}/edit` | Official **Site MCP Knowledge Profile** form (tone/CTA/links/short description) + header **Generate/Regenerate draft** / **Show draft panel**. Draft = fixed right drawer (`site_meta.site_mcp_draft` only; never overwrite official). Views: `edit-domain.blade.php`, `partials/site-mcp-draft-panel.blade.php` |
| **GeneralDomain** | `{record}/general` | **Overview chÃ­nh** (site-specific): API tokens, score distribution, sync stats, top keywords/links, official Site MCP summary + link **Chá»‰nh sá»­a / Generate draft** â†’ Edit. Header: nÃºt **MCP Markdown** â†’ `ViewDomainMcp` (Agent docs â€” khÃ¡c Site MCP). **KhÃ´ng** embed draft generator / global MCP catalog táº¡i Ä‘Ã¢y |
| **ViewDomainMcp** | `{record}/mcp` | Readonly **Developer MCP Reference** (Agent `CanonicalCapabilityRegistry` docs) â€” **khÃ´ng** pháº£i Site MCP Knowledge Profile. Domain URL = navigation shell only. Manager-only |
| **ListDomainInternalLinks** | `{record}/internal-links` | Internal links vá»›i tab keywords/links |
| **RedirectDomainInfoToEdit** | `{record}/info` | Redirect `/info` â†’ `/edit` |
| **DomainGlobalCtaSettings** | `domains/settings` | Global CTA settings (working_hours, zalo...) â€” lÆ°u WpOption |
| **ArticleDomainMismatch** | (no nav) | Cáº£nh bÃ¡o khi article thuá»™c domain â‰  domain hiá»‡n táº¡i, cho phÃ©p switch |
| **AllDomainsListWidget** | widget | Score distribution bars per domain, worst article |
| **AllDomainsProjectsWidget** | widget | Content projects progress per domain |
| **AllDomainsTeamWidget** | widget | Team productivity (articles optimized per content manager) |

---

## 3. Domain Services (14 files)

### 3.1 Technical SEO & Config

| # | Service | File | Chá»©c nÄƒng chÃ­nh |
|---|---------|------|-----------------|
| 1 | **DomainOverviewService** | `Services/DomainOverviewService.php` | Tá»•ng quan domain: API tokens (password-protected), phÃ¢n bá»‘ Ä‘iá»ƒm SEO (poor/fair/good/excellent), sync stats (articles/products/categories), top keywords, top links, technical SEO summary (short description, CTA count, link count) |
| 2 | **AllDomainsDashboardService** | `Services/AllDomainsDashboardService.php` | Dashboard tá»•ng há»£p táº¥t cáº£ domain: health overview, content project progress, team productivity |
| 3 | **SeoMainDomainService** | `Services/SeoMainDomainService.php` | Quáº£n lÃ½ "miá»n chÃ­nh" per user (meta key `seo_is_main`). Set, unset, resolve, deduplicate primary sites |
| 4 | **ClearDomainArticlesService** | `Services/ClearDomainArticlesService.php` | XÃ³a vÄ©nh viá»…n toÃ n bá»™ SeoArticle + SeoPromptResultLink cá»§a domain |
| 5 | **SiteDomainPromptContextService** | `Services/SiteDomainPromptContextService.php` | **Official Site MCP Knowledge Profile** (UI: Edit Domain): tone, short_description (â‰¤300 tá»«), CTA slots, link list, cta_intro. Meta `seo_domain_prompt_context`. Prompt vars `promptVariablesForSite()` |
| 5b | **SiteMcp\*** (draft) | `Services/SiteMcp/` | **Site MCP draft** (Knowledge Profile, khÃ´ng pháº£i Agent MCP): `SiteMcpDiscovery` Æ°u tiÃªn live WP `product_cat` â†’ staging verified â†’ khÃ´ng heuristic; `SiteMcpProductCatIdentity` (canonical term + parent_term_id ká»ƒ cáº£ `0`); `SiteMcpProductCatLiveSource` (GET taxonomy terms / refresh `/terms`); `SiteMcpGenerator` (`news_manual` / `production_catalog` / `ecommerce_catalog`) â€” Main Topics = `product_cat` + `term_id>0` + `parent_term_id===0` only; `SiteMcpDraft` / `Preview` / `KeywordExtractor` / `OfficialGuard` / `ContactDiscovery`. Counts: `product_cat_total` / `root_product_cat` / `child_product_cat`; availability `available\|unavailable\|incomplete`; warning `PRODUCT_CATEGORY_TAXONOMY_CAPABILITY_MISSING` vs `ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE` |
| 6 | **DomainCtaEditorService** | `Services/DomainCtaEditorService.php` | Format CTA list cho article editor (href, plain_text, can_insert) |
| 7 | **DomainLinkListEditorService** | `Services/DomainLinkListEditorService.php` | Cung cáº¥p link list cho article editor kÃ¨m article_count (sá»‘ bÃ i Ä‘Ã£ chÃ¨n anchor). CÃ³ `forArticle()` lá»c theo ná»™i dung bÃ i |
| 8 | **SeoDomainCtaGlobalSettingsService** | `Services/SeoDomainCtaGlobalSettingsService.php` | Global CTA settings (WpOption `seo_domain_cta_global_settings`): `default_cta_intro`, `global_cta` (working_hours, address, facebook, zalo) |

### 3.2 Domain Sync & Resync

| # | Service | File | Chá»©c nÄƒng chÃ­nh |
|---|---------|------|-----------------|
| 9 | **IncrementalDomainSyncRunner** | `Services/IncrementalDomainSyncRunner.php` | Cháº¡y incremental sync theo chunk, Ä‘á»c/ghi cache progress, gá»­i Filament notification |
| 10 | **MetadataDomainSyncRunner** | `Services/MetadataDomainSyncRunner.php` | Cháº¡y metadata resync theo chunk (ngÃ´n ngá»¯, Polylang, SEO meta) |
| 11 | **KeywordDomainResyncService** | `Services/KeywordDomainResyncService.php` | Reset & resync keywords: xÃ³a CTA-blacklisted, xÃ³a orphan linked keywords, rescan articles â†’ link maps, focus keyword sync |
| 12 | **WordPressPluginDomainsOverviewService** | `Services/WordPressPluginDomainsOverviewService.php` | Kiá»ƒm tra version WordPress plugin (omi-seo-ai-bridge) trÃªn tá»«ng domain |
| 13 | **SyncDomainContentService** | `Services/SyncDomainContentService.php` | Äá»“ng bá»™ ná»™i dung tá»« WordPress: full sync, prepareIncrementalSync, processIncrementalChunk, prepareMetadataResync, resetAndFullSync, importPushedItems. Taxonomy: persist `wp_parent_id` ká»ƒ cáº£ `"0"` (khÃ´ng xÃ³a khi parent=0 â€” Site MCP fail-closed) + `wp_term_count` |
| 14 | **DomainLinkListKeywordSyncService** | `Services/DomainLinkListKeywordSyncService.php` | Äá»“ng bá»™ link list â†’ keywords table. Upsert/Remove link trong domain context |

---

## 4. Domain Settings (CTA, Link List, Short Description, Tone)

### 4.1 3-layer Architecture

| Layer | Service | Storage | Fields |
|-------|---------|--------|--------|
| **Global** | `SeoDomainCtaGlobalSettingsService` | WpOption `seo_domain_cta_global_settings` | `default_cta_intro`, `global_cta` (working_hours, address, facebook, zalo) |
| **Domain (official)** | `SiteDomainPromptContextService` | Site meta `seo_domain_prompt_context` | `tone`, `short_description`, `cta_intro`, `cta[]`, `links[]` |
| **Domain (draft)** | `SiteMcp\SiteMcpDraft` | Site meta `site_mcp_draft` | Knowledge Profile draft JSON (topics/pages/generation); AI context = topics only (no URLs); never auto-apply |
| **Form** | `PersistsDomainPromptContext` trait | Auto-save khi form submit + sync link list â†’ keywords |

### 4.2 DomainTechnicalSeoForm (4 sections)

1. **Domain Settings**: tone of voice (select tá»« `SeoPromptSettingsService`)
2. **Short Description**: textarea, â‰¤300 tá»«, word counter
3. **CTA Section**: phone (3 slots), email (3 slots), cta_intro (textarea), CTA repeater (type + value)
4. **Link List**: repeater (keyword + URL)

### 4.3 Form Persistence Flow

```
CreateDomain/EditDomain
  â†’ PersistsDomainPromptContext::queuePromptContextFromFormState()
  â†’ PersistsDomainPromptContext::persistPendingDomainPromptContext()
    â†’ SiteDomainPromptContextService::saveForSite()
    â†’ DomainLinkListKeywordSyncService::syncLinks() (Ä‘á»“ng bá»™ link list â†’ keywords)
```

---

## 5. Domain Sync Cache (Support, 4 files)

| File | Class | Má»¥c Ä‘Ã­ch |
|------|-------|----------|
| `Support/DomainSyncManifestComparator.php` | Comparator | So sÃ¡nh WordPress manifest vs local articles â†’ xÃ¡c Ä‘á»‹nh refs cáº§n fetch (new/update/skip) |
| `Support/IncrementalDomainSyncCache.php` | Cache | State machine: running/completed/failed/resumable, STALE_AFTER_SECONDS=120 |
| `Support/MetadataDomainSyncCache.php` | Cache | TÆ°Æ¡ng tá»± IncrementalDomainSyncCache cho metadata sync |
| `Support/KeywordDomainResyncCache.php` | Cache | State machine + orphan detection (ORPHAN_AFTER_SECONDS=180) |

---

## 6. Domain Functions & Queue Dispatch

| Chá»©c nÄƒng | Queue Job | Service gá»‘c |
|-----------|-----------|-------------|
| **Incremental Sync** (Ä‘á»“ng bá»™ bÃ i viáº¿t má»›i/cáº­p nháº­t tá»« WP) | `RunIncrementalDomainSyncJob` (queue `seo`, unique 2h) | â†’ `IncrementalDomainSyncRunner::run()` |
| **Refresh Article Metadata** (Ä‘á»“ng bá»™ meta WP: ngÃ´n ngá»¯, Polylang, SEO) | `RunMetadataDomainSyncJob` (queue `seo`, unique 2h) | â†’ `MetadataDomainSyncRunner::run()` |
| **Rescrape Keywords** (reset + rescrape keywords tá»« articles) | `RunKeywordDomainResyncJob` (queue) | â†’ `KeywordDomainResyncService::resetAndResync()` |
| **SEO scoring (backfill)** | `AnalyzeArticleSeoJob` (unique per article) | â†’ `SeoArticleScoringQueueService` |
| **Audit Link Health** (kiá»ƒm tra HTTP status cá»§a link maps) | `AuditLinkStatusJob` (queue per link, chunk per domain) | â†’ `LinkMapStatusAuditService::queueDomainAudit()` |
| **Test Sync** (debug) | Äá»“ng bá»™, khÃ´ng queue | â†’ `SyncDomainContentService::performDebugSync()` |

---

## 7. Domain File Index

### Filament (16 files)

| File | Loáº¡i |
|------|------|
| `Filament/Resources/DomainResource.php` | Resource (Site model) |
| `Filament/Resources/DomainResource/Pages/ListDomains.php` | Page |
| `Filament/Resources/DomainResource/Pages/CreateDomain.php` | Page |
| `Filament/Resources/DomainResource/Pages/EditDomain.php` | Page â€” official Site MCP + draft drawer |
| `resources/views/.../edit-domain.blade.php` + `partials/site-mcp-draft-panel.blade.php` | Edit layout + fixed draft drawer |
| `Filament/Resources/DomainResource/Pages/GeneralDomain.php` | Page (overview; link Edit for Site MCP draft; nÃºt MCP Markdown â†’ ViewDomainMcp) |
| `Filament/Resources/DomainResource/Pages/ViewDomainMcp.php` | Page â€” Developer/Agent MCP docs (`/{record}/mcp`) â€” khÃ¡c Site MCP |
| `Services/SiteMcp/{SiteMcpDraft,Discovery,Generator,Preview,KeywordExtractor,OfficialGuard,ContactDiscovery,ProductCatIdentity,ProductCatLiveSource}.php` | Site MCP Knowledge Profile draft pipeline (verified product_cat roots â†’ Main Topics) |
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

## HÆ°á»›ng dáº«n prompt

```
Resource: Filament/Resources/DomainResource.php
Overview page: Filament/Resources/DomainResource/Pages/GeneralDomain.php
Official Site MCP: Services/SiteDomainPromptContextService.php + Forms/DomainTechnicalSeoForm.php + EditDomain
Site MCP draft: Services/SiteMcp/* â†’ site_meta.site_mcp_draft (EditDomain drawer; live/staging product_cat parent=0 â†’ Main Topics)
Developer/Agent MCP docs: ViewDomainMcp (CanonicalCapabilityRegistry) â€” not Site MCP
Global CTA: Filament/Pages/DomainGlobalCtaSettings.php + Services/SeoDomainCtaGlobalSettingsService.php
Dashboard widgets: Filament/Widgets/AllDomains{List,Projects,Team}Widget.php
Sync core: Services/SyncDomainContentService.php
```

