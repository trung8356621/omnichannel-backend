# Site Sync V2 — Shadow Mode

## Behavior

- WP auto delta → V2 inbound + reconcile
- V2 reconciliation on
- Legacy automatic scheduler off for site (`legacySchedulerAllowed=false`)
- Comparison summary can run on schedule
- No duplicate WP publish loop
- Production-facing reads may still show legacy until `v2_active`

## Enter

`EnterSiteSyncShadowMode` via Ops / CommandBus only.
