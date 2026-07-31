> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# SERP Snapshot Model

Model: `SeoSerpSnapshot` â€” connection `omi_seo_ai`, table `seo_serp_snapshots`.

## Immutability

`UPDATED_AT = null`. After status `completed` or `partially_completed`, `assertMutable()` throws â€” snapshots are append-only evidence.

Statuses: `pending` â†’ `collecting` â†’ `normalizing` â†’ `analyzing` â†’ `completed` | `partially_completed` | `failed`.

## Related models

| Model | Ref prefix |
|-------|------------|
| `SeoSerpQuery` | `srpq_` |
| `SeoSerpResult` | `srpr_` |
| `SeoSerpFeature` | `srpf_` |
| `SeoSerpPageEvidence` | `srpe_` |
| `SeoSerpClusterEvidence` | `srpc_` |
| `SeoSerpContentGap` | `srpg_` |

## Scope key

`SerpQueryRequest::scopeKey()` â€” dedupe/cache key includes `device`, `normalized_query`, `language`, `country`, `location`, `search_engine`, `provider`.

Mobile and desktop are distinct scopes.

## Collection lock

`SerpCollectionLockService::collectionKey($serpQueryRef)` â†’ `serp-collection:{ref}`

Used by `SerpCollectionOperationService::collect()` per query ref.

## Checksums

`raw_checksum`, `normalized_checksum` â€” idempotent re-import detection (`SerpImportSnapshotService`).
