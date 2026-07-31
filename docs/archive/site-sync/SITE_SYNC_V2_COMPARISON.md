> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 â€” Comparison

Readonly dual-read. Never mutates. Never full-site HTML parse.

## Groups

Article / Links / Keywords / Scores / Profile / Capabilities

## Classifications

`expected_difference` Â· `harmless_difference` Â· `needs_review` Â· `blocking` Â· `legacy_data_invalid` Â· `v2_data_invalid` Â· `source_ownership_difference` Â· `provider_formula_difference` Â· `normalization_difference`

Scores from different providers = `provider_formula_difference` â€” do not chart as one metric.

## Command

`GenerateSiteSyncComparisonReport` â†’ CSV under `storage/app/site-sync/comparisons/`
