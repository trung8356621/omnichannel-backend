> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 Cutover

Modes: `legacy_active` â†’ `v2_shadow` â†’ `v2_active` (no direct legacyâ†’active unless emergency).

## Services

- `SiteSyncCutoverStateService`
- `SiteSyncCheckpointService`
- `SiteSyncCutoverScorecardService`
- `SiteSyncComparisonService`
- `SiteSyncRepairPlanner`

## Scorecard statuses

`not_ready` Â· `ready_for_shadow` Â· `shadow_observation_required` Â· `ready_for_manual_cutover` Â· `rollback_recommended`

## Related

- [SITE_SYNC_V2_SHADOW_MODE.md](SITE_SYNC_V2_SHADOW_MODE.md)
- [SITE_SYNC_V2_ROLLBACK.md](SITE_SYNC_V2_ROLLBACK.md)
- [SITE_SYNC_V2_COMPARISON.md](SITE_SYNC_V2_COMPARISON.md)
