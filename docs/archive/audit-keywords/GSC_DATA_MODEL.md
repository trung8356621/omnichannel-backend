> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Data Model

Connection: `omi_seo_ai`

## Tables / Models

| Table | Model | Notes |
|-------|-------|-------|
| `seo_gsc_properties` | `SeoGscProperty` | `public_ref` â†’ `gscp_` |
| `seo_gsc_sync_runs` | `SeoGscSyncRun` | `gscs_` |
| `seo_gsc_daily_metrics` | `SeoGscDailyMetric` | Fact rows |
| `seo_gsc_query_mappings` | `SeoGscQueryMapping` | `gscq_` |
| `seo_gsc_page_mappings` | `SeoGscPageMapping` | `gscm_` |
| `seo_gsc_performance_aggregates` | `SeoGscPerformanceAggregate` | `gsca_` |
| `seo_gsc_opportunities` | `SeoGscOpportunity` | `gsco_` |

## Daily facts

- Upsert by `data_hash` â€” **replace** metrics, khÃ´ng cá»™ng dá»“n (`GscDailyMetricPersistService`)
- Dual-write: in-memory (same-request / pure PHPUnit) + Eloquent khi caller truyá»n `property_id` + `site_id`
- Indexes dÃ¹ng `normalized_query_hash` / `normalized_page_hash` (trÃ¡nh utf8mb4 3072-byte limit trÃªn URL dÃ i)
- `data_hash`: `GscFactHashService` (dimensions only)
- Casts (`SeoGscDailyMetric`): `ctr` â†’ `decimal:6`, `position` â†’ `decimal:3`

## Migration

`app/Addons/SeoContentAi/database/migrations/2026_07_28_180000_create_gsc_intelligence_tables.php`  
`$connection = 'omi_seo_ai'`; idempotent `hasTable` + repair hash indexes náº¿u báº£ng partial.

## Public refs

| Prefix | Encoder on `KeywordIntelligencePublicRef` |
|--------|-------------------------------------------|
| `gscp_` | `gscProperty` |
| `gscs_` | `gscSyncRun` |
| `gscq_` | `gscQueryMapping` |
| `gscm_` | `gscPageMapping` |
| `gsca_` | `gscPerformanceAggregate` |
| `gsco_` | `gscOpportunity` |

## Credential / DB boundary

| Store | Connection | Role |
|-------|------------|------|
| `seo_gsc_master_connections`, `seo_gsc_property_mappings` | `mysql` core | OAuth / siteâ†’property mapping (`SeoGscMasterConnection`, `SeoGscPropertyMapping`) |
| `seo_gsc_*` intelligence tables above | `omi_seo_ai` | Canonical facts / mappings / opportunities |

KhÃ´ng duplicate OAuth credential vÃ o `omi_seo_ai`. Legacy Performance Hub KPI váº«n Ä‘á»c SiteMeta `gsc_query_snapshot` (core) â€” tÃ¡ch biá»‡t stack intelligence.

## CSV import columns

`date,query,page,country,device,search_appearance,clicks,impressions,ctr,position`

CTR recalculated server-side; reject `clicks > impressions`.
