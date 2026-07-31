> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/ARTICLE_EDITOR.md
> Purpose: implementation history only
# Article Editor Phase 2 â€” Bootstrap Size Measurements

**Date:** 2026-07-22
**Method:** `php -r` / standalone PHP script â€” `strlen(json_encode($data, JSON_UNESCAPED_UNICODE))`, no Laravel boot, no DB. Same formula as `ArticleEditorBootstrapSizer::bytes()`.
**Fixtures:** synthetic (not scraped from a real article) â€” shaped to match the Phase 1 blade embeds and the Phase 2 `getEditorCoreBootstrap()` contract. See `app/Addons/SeoContentAi/tests/Unit/ArticleEditorBootstrapSizerTest.php` for the exact fixture arrays (kept in sync with this table).
**Cross-check:** `ArticleEditorBootstrapSizerTest::test_before_after_fixture_reduction_meets_phase2_target` asserts these numbers stay under the Phase 2 acceptance bar on every test run.

---

## BEFORE â€” Phase 1 blade embeds (per-piece)

| Script id (Phase 1) | Fixture shape | Bytes | KB |
|---|---|--:|--:|
| `seo-article-initial-html` | 2000Ã— `<p>Paragraphâ€¦</p>` (â‰ˆ58.6 KB body) | 60,002 | 58.60 |
| `seo-article-initial-seo` | `forEditorBootstrap` shape, empty catalogs + SERP preview + 8 violations | 2,233 | 2.18 |
| `seo-article-initial-images` | 20 image rows | 6,581 | 6.43 |
| `seo-article-editor-settings` | scoring rules (40) + rule messages (40) + scoring messages (20) + flags | 9,340 | 9.12 |
| `seo-article-meta` | supplemental images (10) + product_gallery (5) + product_category_options (30) + ai_debug | 5,070 | 4.95 |
| `seo-article-initial-faqs` | 10 FAQ rows | 2,513 | 2.45 |
| **TOTAL (all pieces)** | | **85,796** | **83.79** |
| **TOTAL excl. content** | (settings + seo + images + meta + faqs) | **25,794** | **25.19** |

## AFTER â€” Phase 2 core bootstrap (`getEditorCoreBootstrap()`)

| Field | Notes |
|---|---|
| `articleId`, `connectionHash`, `siteId`, `title`, `slug`, `content`, `status`, `postType` | identity + content (same body as before â€” not duplicated) |
| `updatedAt`, `expectedUpdatedAt`, `expectedContentHash` | conflict guard tokens |
| `featuredImageUrl`, `supportsProductGallery` | small scalars |
| `endpoints.*` | 8 lazy endpoint URLs (strings only) |
| `settings.*` | `history_step`, `autosave_interval_seconds`, `wiki_trust_domains`, permission flags, `prompt_hooks` â€” **no** `seo_scoring_rules` / `seo_rule_messages` / `seo_scoring_messages` |

| Metric | Bytes | KB |
|---|--:|--:|
| **TOTAL (incl. content)** | 61,541 | 60.10 |
| **TOTAL (excl. content)** | 1,528 | 1.49 |

---

## Reduction

| Comparison | Result |
|---|---|
| After (excl. content) vs Before TOTAL (all pieces) | 1,528 / 85,796 = **98.2% smaller** |
| After (excl. content) vs Before TOTAL (excl. content) | 1,528 / 25,794 = **94.1% smaller** |
| Phase 2 acceptance: after non-content < 15 KB | 1.49 KB â€” **pass** |
| Phase 2 acceptance: after non-content < 50% of before | 1,528 < 12,897 (50% of 25,794) â€” **pass** |

`seo_scoring_rules` + `seo_rule_messages` + `seo_scoring_messages` alone accounted for ~9.1 KB of the Phase 1 `settings` embed â€” now loaded once via `GET .../editor/settings`, shared across SEO summary + Links + FAQ panels instead of being duplicated in both `seo-article-initial-seo` and `seo-article-editor-settings`.

---

## Notes / caveats

- Numbers are **synthetic fixture** sizes, not scraped from a production article. Real articles vary (FAQ count, image count, keyword catalog size) â€” the *shape* of the reduction (dropping catalogs/rules/messages/FAQ/images from the initial payload) is what generalizes, not the exact byte counts.
- `content` (article body HTML) is intentionally **excluded** from the "reduction" comparison â€” both phases ship the body once; Phase 2 targets everything *around* it.
- Runtime (production) `article_editor_bootstrap_sizes` sizes are available per-request when `ARTICLE_EDITOR_PERF_DEBUG=true` â€” see `ArticleEditorBootstrapSizer::log()` (Log channel `article_editor_bootstrap_sizes` + best-effort `storage/logs/article_editor_bootstrap_sizes.json` snapshot). Ops should fill in a real before/after table here once that's collected on staging (rule: khÃ´ng bá»‹a sá»‘ â€” placeholder below).

| Metric (production, real article) | Before | After | Status |
|---|--:|--:|---|
| Total document size | â€” | â€” | not measured yet |
| Total XHR after idle (Phase 2 lazy fetches) | â€” | â€” | not measured yet |
| DB query count on `EditArticle::mount` + render | â€” | â€” | not measured yet |
