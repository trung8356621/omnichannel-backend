# Keyword Analysis Operations (Phase 2)

## Pipeline

`AnalyzeKeywordWorkspace` / `AnalyzeSelectedKeywords` điều phối:

1. `normalize`
2. `deduplicate` (exact via import resolver + near-duplicate candidates)
3. `classify` (manual → rule → optional AI via `AiProviderResolver`)
4. `score`
5. `map_existing_content`
6. `cluster` (strict|balanced|broad)
7. `detect_cannibalization`
8. `finalize`

**Không** build Topical Map / Content Project trong phase này.

## Lock

Key: `keyword-workspace-analysis:{workspace_ref}`

- Owner token qua `ContentProjectBusinessLock`
- TTL: `keyword_intelligence.analysis.lock_ttl_seconds` (default 900)
- Không forceRelease
- Cùng `idempotency_key` trả operation cũ
- Busy → `keyword.analysis_already_processing`

## Operation fields

`seo_keyword_analysis_operations`: status, current_stage, total/processed/failed keywords, progress_percent, started_at/finished_at, warnings_count, idempotency_key, cancel_requested, options, keyword_scope.

## Manual overrides

`field_sources.{field}.source = manual` → analysis không overwrite.

## Missing metrics

Score không giả volume/difficulty = 0. Confidence giảm + warnings `keyword.missing_*`.

## Cluster protection

Approved/reviewed clusters không bị mutate khi `recluster_draft_only=true` (default).

Merge/split yêu cầu preview token khi approved / mixed intent.
