# Site Sync V2 — Bootstrap

First-time sync for sites without V2 state.

## Flow

1. Check plugin/contract
2. Capability + lightweight manifest
3. Preview workload (batches)
4. Confirm → `BootstrapSiteSync` CommandBus
5. Snapshot orchestrator (queued steps)
6. Stamp `seo_site_sync_v2_bootstrapped_at` on finalize

## UI

One button **Đồng bộ & kiểm tra website**:
- chưa bootstrap → preview + xác nhận
- đã bootstrap → incremental

## Commands

- `site.preview_bootstrap`
- `site.bootstrap`
