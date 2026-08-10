# Quick Documentation Summary

> Status: working summary, not canonical source of truth  
> Updated: 2026-08-07  
> Purpose: summarize recent conversation/work context so the next session can re-orient quickly. Canonical behavior still lives in `docs/README.md` and the linked architecture/module/contract docs.

## 1. Documentation Map

The current documentation set is organized as a canonical docs system for the Omnichannel Laravel backend.

- `docs/README.md` is the starting index and precedence guide.
- `docs/architecture/*` records system boundaries, frozen architecture rules, and ADRs.
- `docs/modules/*` describes each business module.
- `docs/contracts/*` records public contracts and invariants.
- `docs/operations/*` covers deploy, testing, scheduler/workers, and troubleshooting.
- `docs/audits/*` contains active audits.
- `docs/archive/*` is historical only and must not be treated as source of truth.

## 2. Recent Conversation Themes

### Article Editor

- The editor is treated as a dedicated island: Livewire shell plus React/TipTap runtime.
- Recent work focused on media ownership, featured image/gallery behavior, close/sync behavior, slug/version conflict handling, and lock-related regressions.
- Any change must preserve session lock, `document_version`, TipTap JSON document model, command layer, media snapshot ownership, and WordPress sync separation.
- WordPress sync must not be conflated with local editor save; conflict policy and field ownership need to remain explicit.

### Content Projects

- Content Project lifecycle writes should continue through `ContentProjectCommandBus`.
- Recent work touched archive/restore/reset flows, rerun behavior, queue states, workspace cleanup, and task sync.
- False pending/generate states, outline rewrite, and archive preview restore behavior are active areas to keep in mind.
- Generated/AI workspace cleanup must avoid deleting user-owned or canonical media accidentally.

### Publishing And Queue Runtime

- Laravel remains the source of truth for publishing schedule and queue state.
- WordPress receives publish/sync outcomes; it is not the schedule owner.
- Recent incidents and fixes centered on dispatch stalls, retry/idempotency, stuck recovery, immediate publish rewrite, and queue UI clarity.
- Publishing Queue behavior should be checked against `docs/modules/PUBLISHING.md` and `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`.

### WordPress Bridge And Sync

- Backend/plugin REST contracts require cross-repo inspection with `../wp-seo-ai`.
- Recent context includes preserving WordPress slugs, manual WordPress ID linking, post-publish sync contract, Site Sync stuck recovery, and WordPress field conflict policy.
- Token-based bridge auth, tenant boundaries, and narrow CSRF exceptions must be preserved.
- Backend deploy-diff tracking does not cover the sibling WordPress plugin repo.

### Media, Gallery, And Images

- Recent work touched media picker behavior, source classification, local media cleanup, WebP minimum width, attachment rename/slug fixes, and image optimization tests.
- Media ownership must distinguish local article media, WordPress-origin media, generated media, and gallery/featured snapshots.
- Article Editor media snapshot rules remain the primary guardrail for UI/runtime changes.

### Google Search Console And SEO Audit

- Recent work included GSC missing-table handling, connection service tests, SEO audit scan fixes, and reason/metrics syntax fixes.
- Verification should cover service-level tests when touching GSC or audit scan behavior.

### UI And Admin UX

- Recent UI work included article list, pagination/block-all, publish/sidebar controls, prompt editor UX, queue bulk UI, lock-at-capacity, and assorted Filament/React cleanup.
- Blade select boxes should use `<x-select>`.
- SEO React controls should use `SeoSelect`.
- Modals, drawers, and popovers should open/close immediately through Alpine/JavaScript; Livewire should handle loading, validation, persistence, and server actions.

## 3. Current Working-Tree Caution

At the time this summary was updated, the worktree already contained many modified application files and docs from prior tasks. Future agents should inspect `git status --short` before editing and must not revert unrelated user/agent changes.

Notable modified areas seen:

- Article Editor PHP, React runtime, media utilities, and tests.
- Content Project archive/restore/sync services and tests.
- WordPress sync services and contract tests.
- SEO image optimization, media slug fix, GSC, audit scan, and related tests.
- Canonical docs for Article Editor, Content Projects, and WordPress Bridge.

## 4. Operational Rules To Remember

- For non-trivial tasks, query `codebase-memory` near the start when available, then verify against code/docs.
- Before application-code edits, start or reuse a deploy-diff session.
- After meaningful application-code edits, run deploy-diff `track` for modified/deleted backend files.
- Do not deploy, commit, push, install dependencies, run migrations, alter databases, upload, or package the plugin unless explicitly asked.
- Use remote-first PHPUnit commands, normally `$PHP_BIN vendor/bin/phpunit --filter=...`.
- For JS/CSS changes, run or report the relevant build/check, normally `npm run build`.

## 5. Documentation Update Policy

- This file is a lightweight conversation digest.
- It does not override canonical docs.
- When the user says `XONG!`, update the relevant canonical docs through the project docs workflow.
- Contract/API/auth/site-sync/publishing/article/media changes should update the matching module/contract docs only after behavior is verified in code.

## 6. Fast Re-Entry Checklist

1. Read `docs/README.md`.
2. Check `git status --short`.
3. Read the relevant module/contract docs for the touched area.
4. Inspect current code and nearby tests before editing.
5. Start/reuse deploy-diff for application-code changes.
6. Run focused verification or clearly report why it was skipped.
