# Article Editor Analysis Ownership (Phase 2B)

> Status: Implemented (Phase 2B)  
> Task ID: `article-editor-analysis-ownership-phase-2b`  
> Related: [`ARTICLE_EDITOR_MEDIA_SNAPSHOT.md`](ARTICLE_EDITOR_MEDIA_SNAPSHOT.md), [`ARTICLE_EDITOR_SESSION_LOCK.md`](ARTICLE_EDITOR_SESSION_LOCK.md)

## Ownership

| Concern | Owner |
|---------|--------|
| Immediate word/link/heading/keyword/image-ratio analysis | **React** (`composeImmediateArticleAnalysis` → `computeSeoAnalysis` + policy) |
| Widget health presentation | React (`assistantWidgetHealth`) consuming composed analysis + media snapshot |
| Thresholds / reason registry / aliases | **Laravel** `ArticleEditorAnalysisPolicyService` |
| External facts (trust/wiki refresh flags) | Laravel `external_facts` |
| Canonical persist / publish validation | Laravel `SeoAnalyzerService` / `SeoScoringEngine` (unchanged) |
| Livewire `seo-analyze-result` | **Removed** as UI SoT (no Blade/Livewire bridge) |

## Policy bootstrap

Core bootstrap + settings lazy payload:

- `analysisPolicy` / `analysis_policy`
- `externalFacts` / `external_facts`

Client: `setAnalysisPolicy()` / `setExternalFacts()` → `window.__SEO_ANALYSIS_POLICY__` / `__SEO_EXTERNAL_FACTS__`.

Canonical thresholds (PHP constants consumed by policy):

- `SeoReasonPresentation::TARGET_WORDS_PER_IMAGE = 200`
- `AssistantWidgetHealthRules::MIN_VALID_HTTP_LINKS = 5`
- article length from `SeoPromptSettingsService`

## Input / output

Input (composed): document HTML, focus keyword, article type, media snapshot (via article id store), external facts, policy.

Output: `{ score, violations, metrics, reasons, analysis_owner: 'react_immediate', policy_version }`.

Image counts prefer `mediaSnapshot.content_images` via `resolveContentImageCounts`.

## Re-analyze semantics

Local checks (length, links, ratio, keyword, featured/gallery presentation) update live (~250ms debounce).

Re-analyze / retry label = refresh **external/server facts**, not required for local score.

Save API may emit `seo-editor-analyze-result` → React **recomputes locally** (does not adopt server score as SoT).

## Widget severity

- Images: integrity = error; ALT = warning; `image_ratio_low` = **info** (not red badge as hard error)
- Links: `links_below_minimum` with `current/minimum/missing` params from policy
- Featured/Gallery: media snapshot Phase 2A + policy `featured.alt_required` / `gallery.required`

## Remaining Phase 2C

- FAQ / CTA ownership
- Full TipTap-JSON-only parser (no dual HTML/DOM paths)
- Browser E2E caret proof
- Optional: drop save-patch analyze nudge if redundant with local idle analyze

## Legacy cleanup

Orphan seo-editor-seo-analysis-updated producer removed. Analysis ownership unchanged. See [ARTICLE_EDITOR_LEGACY_CLEANUP.md](ARTICLE_EDITOR_LEGACY_CLEANUP.md).
