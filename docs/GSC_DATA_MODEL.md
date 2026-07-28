# GSC Data Model

Connection: `omi_seo_ai`

## Tables / Models

| Table | Model | Notes |
|-------|-------|-------|
| `seo_gsc_properties` | `SeoGscProperty` | `public_ref` → `gscp_` |
| `seo_gsc_sync_runs` | `SeoGscSyncRun` | `gscs_` |
| `seo_gsc_daily_metrics` | `SeoGscDailyMetric` | Fact rows |
| `seo_gsc_query_mappings` | `SeoGscQueryMapping` | `gscq_` |
| `seo_gsc_page_mappings` | `SeoGscPageMapping` | `gscm_` |
| `seo_gsc_performance_aggregates` | `SeoGscPerformanceAggregate` | `gsca_` |
| `seo_gsc_opportunities` | `SeoGscOpportunity` | `gsco_` |

## Daily facts

- Upsert by `data_hash` — **replace** metrics, không cộng dồn (`GscDailyMetricPersistService`)
- Dual-write: in-memory (same-request / pure PHPUnit) + Eloquent khi caller truyền `property_id` + `site_id`
- Indexes dùng `normalized_query_hash` / `normalized_page_hash` (tránh utf8mb4 3072-byte limit trên URL dài)
- `data_hash`: `GscFactHashService` (dimensions only)
- Casts (`SeoGscDailyMetric`): `ctr` → `decimal:6`, `position` → `decimal:3`

## Migration

`app/Addons/SeoContentAi/database/migrations/2026_07_28_180000_create_gsc_intelligence_tables.php`  
`$connection = 'omi_seo_ai'`; idempotent `hasTable` + repair hash indexes nếu bảng partial.

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
| `seo_gsc_master_connections`, `seo_gsc_property_mappings` | `mysql` core | OAuth / site→property mapping (`SeoGscMasterConnection`, `SeoGscPropertyMapping`) |
| `seo_gsc_*` intelligence tables above | `omi_seo_ai` | Canonical facts / mappings / opportunities |

Không duplicate OAuth credential vào `omi_seo_ai`. Legacy Performance Hub KPI vẫn đọc SiteMeta `gsc_query_snapshot` (core) — tách biệt stack intelligence.

## CSV import columns

`date,query,page,country,device,search_appearance,clicks,impressions,ctr,position`

CTR recalculated server-side; reject `clicks > impressions`.
