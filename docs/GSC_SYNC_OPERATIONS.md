# GSC Sync Operations

Path: `Services/GscIntelligence/GscSyncOperationService.php`

## Lock

- Key: `gsc-sync:{property_ref}` via `GscSyncLockService`
- TTL: `gsc_intelligence.lock.ttl_seconds` (default 600)

## Date ranges (`GscSyncDateRangeService`)

- `sync.data_delay_days` (default 3) — latest available end date
- `sync.incremental_overlap_days`, `sync.max_days_per_chunk`

## Stages (`GscSyncStage`)

`preparing` → `fetching` → `normalizing` → `persisting` → `mapping` → `aggregating` → `detecting` → `finalizing` → `completed` | `partially_completed` | `failed`

Partial when provider returns valid rows **and** `invalid_count > 0`.

## Cancel

`GscSyncOperationService::cancel($operationRef)` returns `false` after terminal stages (`completed`, `partially_completed`, `failed`, `cancelled`).

Command: `CancelGscSyncCommand` (`gsc_intelligence.cancel_sync`).

## Persist after sync

- Daily facts: dual-write khi context có `property_id` / `site_id_int`
- Suggested mappings: `GscSuggestedMappingPersistService` (skip manual)
- Opportunities trong sync result là in-run list; durable rows qua `DetectGscOpportunitiesCommand`

## Out of scope

Live Google Search Analytics HTTP/SDK adapter (legacy `GoogleSearchConsoleSyncService` + SiteMeta snapshot vẫn tồn tại cho Performance Hub cũ).

## Commands

- `SyncGscPerformanceDataCommand`
- `ImportGscPerformanceDataCommand`
- `RepairGscDateRangeCommand`
