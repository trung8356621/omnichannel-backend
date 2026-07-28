# SERP Snapshot Model

Model: `SeoSerpSnapshot` — connection `omi_seo_ai`, table `seo_serp_snapshots`.

## Immutability

`UPDATED_AT = null`. After status `completed` or `partially_completed`, `assertMutable()` throws — snapshots are append-only evidence.

Statuses: `pending` → `collecting` → `normalizing` → `analyzing` → `completed` | `partially_completed` | `failed`.

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

`SerpQueryRequest::scopeKey()` — dedupe/cache key includes `device`, `normalized_query`, `language`, `country`, `location`, `search_engine`, `provider`.

Mobile and desktop are distinct scopes.

## Collection lock

`SerpCollectionLockService::collectionKey($serpQueryRef)` → `serp-collection:{ref}`

Used by `SerpCollectionOperationService::collect()` per query ref.

## Checksums

`raw_checksum`, `normalized_checksum` — idempotent re-import detection (`SerpImportSnapshotService`).
