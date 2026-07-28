# Keyword → Content Project (Phase 3)

## Source

Approved topical map version (`seo_topical_map_versions.status=approved`).

Draft map is **not** convertible by default. Agent cannot override.

## Policy

| Policy | Behavior |
|--------|----------|
| new_only | write_new only |
| new_and_rewrite | write_new + rewrite with evidence |
| all_reviewed_actions | include improve when evidence+description |
| manual_selection | user picks item types |

## Action resolver

`KeywordClusterContentActionResolver` → write_new | rewrite | improve | covered | blocked | needs_review

`suggested_content_type` (article/landing/faq/…) ≠ Content Project item type.

Covered excluded by default. landing_page ≠ rewrite.

## Tables

- `seo_keyword_project_conversions` — idempotency (`idempotency_key_hash`), status previewed|processing|completed|failed
- `seo_keyword_content_project_links` — traceability (origin/rewrite_target/improve_target/…)

## Flow

Preview token (existing confirmation infra) → CreateContentProjectCommand via CommandBus → items → links → finalize.

Failure must not mark conversion `completed`.

No auto schedule/publish. No gallery_description. No live sync after convert.

Archive Content Project: keep KI workspace/topics/versions/links.

## Phase 4 — SERP evidence in preview (additive)

`SerpEvidenceContentProjectPreviewAdapter` stub merges optional `serp_evidence` (intent, gaps) vào preview item — không đụng `gallery_description`. Full wiring via convert commands in later phase. See [SERP_CONTENT_GAPS.md](SERP_CONTENT_GAPS.md).

## Phase 5 — GSC opportunities in preview (additive)

`GscContentProjectPreviewBuilder` / `GscOpportunityContentProjectConverter` — `improve_description` / `rewrite_brief` from GSC metrics; **never** `gallery_description`. See [GSC_CONTENT_PROJECT_PERFORMANCE.md](GSC_CONTENT_PROJECT_PERFORMANCE.md).
