> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Sync Operations

Path: `Services/GscIntelligence/GscSyncOperationService.php`

## Lock

- Key: `gsc-sync:{property_ref}` via `GscSyncLockService`
- TTL: `gsc_intelligence.lock.ttl_seconds` (default 600)

## Date ranges (`GscSyncDateRangeService`)

- `sync.data_delay_days` (default 3) â€” latest available end date
- `sync.incremental_overlap_days`, `sync.max_days_per_chunk`

## Stages (`GscSyncStage`)

`preparing` â†’ `fetching` â†’ `normalizing` â†’ `persisting` â†’ `mapping` â†’ `aggregating` â†’ `detecting` â†’ `finalizing` â†’ `completed` | `partially_completed` | `failed`

Partial when provider returns valid rows **and** `invalid_count > 0`.

## Cancel

`GscSyncOperationService::cancel($operationRef)` returns `false` after terminal stages (`completed`, `partially_completed`, `failed`, `cancelled`).

Command: `CancelGscSyncCommand` (`gsc_intelligence.cancel_sync`).

## Persist after sync

- Daily facts: dual-write khi context cÃ³ `property_id` / `site_id_int`
- Suggested mappings: `GscSuggestedMappingPersistService` (skip manual)
- Opportunities trong sync result lÃ  in-run list; durable rows qua `DetectGscOpportunitiesCommand`

## Out of scope

Live Google Search Analytics HTTP/SDK adapter (legacy `GoogleSearchConsoleSyncService` + SiteMeta snapshot váº«n tá»“n táº¡i cho Performance Hub cÅ©).

## Commands

- `SyncGscPerformanceDataCommand`
- `ImportGscPerformanceDataCommand`
- `RepairGscDateRangeCommand`
