> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Keyword â†’ Content Project (Phase 3)

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

`KeywordClusterContentActionResolver` â†’ write_new | rewrite | improve | covered | blocked | needs_review

`suggested_content_type` (article/landing/faq/â€¦) â‰  Content Project item type.

Covered excluded by default. landing_page â‰  rewrite.

## Tables

- `seo_keyword_project_conversions` â€” idempotency (`idempotency_key_hash`), status previewed|processing|completed|failed
- `seo_keyword_content_project_links` â€” traceability (origin/rewrite_target/improve_target/â€¦)

## Flow

Preview token (existing confirmation infra) â†’ CreateContentProjectCommand via CommandBus â†’ items â†’ links â†’ finalize.

Failure must not mark conversion `completed`.

No auto schedule/publish. No gallery_description. No live sync after convert.

Archive Content Project: keep KI workspace/topics/versions/links.

## Phase 4 â€” SERP evidence in preview (additive)

`SerpEvidenceContentProjectPreviewAdapter` stub merges optional `serp_evidence` (intent, gaps) vÃ o preview item â€” khÃ´ng Ä‘á»¥ng `gallery_description`. Full wiring via convert commands in later phase. See [SERP_CONTENT_GAPS.md](SERP_CONTENT_GAPS.md).

## Phase 5 â€” GSC opportunities in preview (additive)

`GscContentProjectPreviewBuilder` / `GscOpportunityContentProjectConverter` â€” `improve_description` / `rewrite_brief` from GSC metrics; **never** `gallery_description`. See [GSC_CONTENT_PROJECT_PERFORMANCE.md](GSC_CONTENT_PROJECT_PERFORMANCE.md).
