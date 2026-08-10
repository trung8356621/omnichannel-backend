---
name: docs-update-on-xong
description: "Trigger only when the user message contains XONG! (case-insensitive). Update the relevant canonical backend docs for code changes just completed. Do not trigger without XONG! and do not update archive docs."
---

# Purpose

Keep canonical backend docs aligned after the user explicitly signals completion with `XONG!`.

# Trigger conditions

- Use only when the latest user message contains `XONG!`, case-insensitive.
- Do not use for normal coding tasks or general documentation requests.

# Required context

- Changed files from current conversation.
- `git status` and `git diff` if needed.
- `docs/README.md` module index.

# Workflow

1. Identify changed symbols, routes, components, services, jobs, queues, or contracts.
2. Select canonical docs from `docs/README.md` (and `docs/architecture/ADDON_ARCHITECTURE.md` / `NEW_AGENT_HANDOFF.md` for ownership).
3. Patch only the relevant section. Route feature ownership: content/media/seo/wordpress/publishing/content-projects/ai-prompt/search-intelligence/site-sync/agent — not SeoContentAi.
4. Do not create new docs outside `docs/modules`, `docs/contracts`, `docs/architecture`, or `docs/operations` unless the user asks.
5. Do not update `docs/archive/*` as source of truth.
6. Do not instruct agents to add business code under `app/Addons/SeoContentAi`.

# Verification

- Review scoped diff for changed docs.
- Confirm no application source code changed as part of docs update unless already part of the active task.

# Safety and approval boundaries

- MUST NOT run when `XONG!` is absent.
- MUST NOT rewrite full large docs when a focused patch works.
- MUST NOT copy archive docs into canonical docs without verifying current code.

# Expected final report

One concise Vietnamese sentence:

```text
Da cap nhat xong docs: <file-list>.
```
