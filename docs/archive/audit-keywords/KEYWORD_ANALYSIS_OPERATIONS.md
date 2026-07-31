> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Keyword Analysis Operations (Phase 2)

## Pipeline

`AnalyzeKeywordWorkspace` / `AnalyzeSelectedKeywords` Ä‘iá»u phá»‘i:

1. `normalize`
2. `deduplicate` (exact via import resolver + near-duplicate candidates)
3. `classify` (manual â†’ rule â†’ optional AI via `AiProviderResolver`)
4. `score`
5. `map_existing_content`
6. `cluster` (strict|balanced|broad)
7. `detect_cannibalization`
8. `finalize`

**KhÃ´ng** build Topical Map / Content Project trong phase nÃ y.

## Lock

Key: `keyword-workspace-analysis:{workspace_ref}`

- Owner token qua `ContentProjectBusinessLock`
- TTL: `keyword_intelligence.analysis.lock_ttl_seconds` (default 900)
- KhÃ´ng forceRelease
- CÃ¹ng `idempotency_key` tráº£ operation cÅ©
- Busy â†’ `keyword.analysis_already_processing`

## Operation fields

`seo_keyword_analysis_operations`: status, current_stage, total/processed/failed keywords, progress_percent, started_at/finished_at, warnings_count, idempotency_key, cancel_requested, options, keyword_scope.

## Manual overrides

`field_sources.{field}.source = manual` â†’ analysis khÃ´ng overwrite.

## Missing metrics

Score khÃ´ng giáº£ volume/difficulty = 0. Confidence giáº£m + warnings `keyword.missing_*`.

## Cluster protection

Approved/reviewed clusters khÃ´ng bá»‹ mutate khi `recluster_draft_only=true` (default).

Merge/split yÃªu cáº§u preview token khi approved / mixed intent.
