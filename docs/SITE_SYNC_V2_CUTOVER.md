# Site Sync V2 Cutover

Modes: `legacy_active` → `v2_shadow` → `v2_active` (no direct legacy→active unless emergency).

## Services

- `SiteSyncCutoverStateService`
- `SiteSyncCheckpointService`
- `SiteSyncCutoverScorecardService`
- `SiteSyncComparisonService`
- `SiteSyncRepairPlanner`

## Scorecard statuses

`not_ready` · `ready_for_shadow` · `shadow_observation_required` · `ready_for_manual_cutover` · `rollback_recommended`

## Related

- [SITE_SYNC_V2_SHADOW_MODE.md](SITE_SYNC_V2_SHADOW_MODE.md)
- [SITE_SYNC_V2_ROLLBACK.md](SITE_SYNC_V2_ROLLBACK.md)
- [SITE_SYNC_V2_COMPARISON.md](SITE_SYNC_V2_COMPARISON.md)
