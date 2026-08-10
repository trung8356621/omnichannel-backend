# Codex Project Instructions

## Repository Role

- **Staging split (Task 11):** canonical trees under `D:\work\_split\` — see `SPLIT_STAGING.md` and `_split/omnichannel-client/docs/architecture/REPO_SPLIT.md`.
- This repository (`omnichannel-backend`) is the **pre-split** Laravel SaaS backend retained until cutover.
- Stack: Laravel 12, PHP 8.2+, Filament 3, React, Vite, Tailwind CSS, MySQL.
- Peer addons (post-split): `omnichannel-addons/{slug}`; Client Core: `omnichannel-client-core`; Shell: `omnichannel-client`.
- The sibling WordPress plugin lives at `..\wp-seo-ai`.

## Source Of Truth

- Code/runtime/manifests MUST win when command, route, binding, scheduler, or script behavior conflicts with docs.
- Start from `README.md` and `docs/README.md`.
- Canonical docs are `docs/architecture/*`, `docs/modules/*`, `docs/contracts/*`, `docs/operations/*`, and current `docs/audits/*`.
- `docs/archive/*`, `docs/SUPER_MAP_INDEX.md`, and `app/Addons/SeoContentAi/README_ADDON_SEOCONTENTAI.md` MUST NOT be used as source of truth.
- For module work, read the relevant canonical docs listed in `docs/README.md`, especially:
  - Content Projects: `docs/modules/CONTENT_PROJECTS.md`
  - Publishing Queue: `docs/modules/PUBLISHING.md`
  - Site Sync: `docs/modules/SITE_SYNC.md`
  - WordPress Bridge: `docs/modules/WORDPRESS_BRIDGE.md`
  - Site MCP/Domains: `docs/modules/SITE_MCP_AND_DOMAINS.md`
  - Agent/MCP: `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`
  - Prompts/AI: `docs/modules/PROMPTS_AND_AI.md`
  - Article Editor: `docs/modules/ARTICLE_EDITOR.md` plus matching `docs/architecture/ARTICLE_EDITOR_*.md`
  - Extension SDK: `docs/modules/EXTENSION_SDK.md` and `docs/contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md`
  - Operations: `docs/operations/*`

## Mandatory Work Sequence

- For any non-trivial task, Codex MUST query `codebase-memory` near the start for relevant prior decisions/context when the MCP server is available.
- `codebase-memory` is NOT source of truth. Codex MUST verify memory results against current code, manifests, routes, container bindings, scheduler, tests, and canonical docs before acting.
- Before changing code, Codex MUST inspect the relevant implementation, nearby tests, closest README/canonical docs, and cross-repo contract consumers when applicable.
- Before the first application-code edit in a task, Codex MUST ensure a deploy-diff session has been started with a short stable kebab-case task id.
- If the correct session already exists, Codex MUST NOT start a duplicate session.
- After meaningful application-code changes, Codex MUST run deploy-diff `track` for every modified/deleted application file before final response, or clearly report why tracking could not run.
- Codex MUST report changed files and verification commands run or intentionally not run.
- Codex MUST NOT claim work is deploy-ready unless tracked diff was checked.

## Deploy Diff Workflow

- Verified script: `.secure/deploy-diff.ps1`.
- Supported modes: `start`, `track`, `deploy`, `cancel`, `list`.
- `start`, `track`, `deploy`, and `cancel` REQUIRED `-Id`.
- `track` REQUIRED one or more `-Path`; it also accepts `-Action modified|deleted`.
- The script has NO `-Current` parameter. Codex MUST NOT use `-Current`.
- Correct syntax:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\deploy-diff.ps1" -Mode start -Id "<task-id>"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\deploy-diff.ps1" -Mode track -Id "<task-id>" -Path "<file1>","<file2>"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\deploy-diff.ps1" -Mode deploy -Id "<task-id>"
```

- `deploy` MUST run ONLY WHEN the user explicitly asks to deploy.
- The script tracks only files under this backend repo root. It does NOT cover `..\wp-seo-ai`.
- WordPress plugin packaging is separate (`compress_plugin.ps1`) and MUST run ONLY WHEN the user explicitly requests plugin packaging/release.

## Always-On Guardrails

- Codex MUST NOT read `.env`, private keys, tokens, passwords, credentials, deployment logs, or full `.secure`; only read `.secure/deploy-diff.ps1` when needed for workflow.
- Codex MUST NOT store secrets, `.env` values, tokens, production logs, credentials, or speculation in `codebase-memory`.
- Codex MUST write memory ONLY WHEN a durable decision has been verified from code/docs or explicitly confirmed by the user.
- Codex MUST NOT deploy, commit, push, install dependencies, run migrations, alter databases, upload via FTP/SFTP/SCP/rsync, or bump plugin version unless the user explicitly asks.
- Codex MUST NOT remove compatibility endpoints, adapters, handlers, routes, classes, or shims until zero caller is proven across routes, container bindings, scheduler, events/listeners, queues/jobs, tests, docs/contracts, and the WordPress plugin.
- Codex MUST preserve authorization, tenant boundaries, token-based WordPress bridge auth, and narrow CSRF exceptions.
- HTTP/Filament/Livewire/editor logging MUST use `App\Support\RuntimeLogger` or `Log::channel('web_app')`; HTTP code MUST NOT write default `laravel.log`.
- Blade select boxes MUST use `<x-select>`; SEO React MUST use `SeoSelect`.
- Modals/drawers/popovers MUST open/close through Alpine/JavaScript immediately; Livewire is for loading, validation, persistence, and server actions.

## Laravel And Addons

- New PHP files MUST use `declare(strict_types=1);`.
- Prefer typed properties, return types, enums, constructor promotion, match, nullsafe, early returns, Form Requests, thin controllers, and focused Service/Action classes.
- Business logic MUST use injection over `app()` resolution unless an existing local pattern requires otherwise.
- Core models use default `mysql`.
- SEO domain DB uses runtime connection `omi_seo_ai`, bootstrapped by `SeoDatabaseConnectionService` from core table `seo_database_connections`.
- **Peer addons live under `addons/{slug}/`** (content, seo, media, wordpress, publishing, content-projects, ai-prompt, search-intelligence, search-foundation, site-sync, agent, social, commerce). See `docs/architecture/ADDON_ARCHITECTURE.md`.
- `app/Addons/SeoContentAi` is **compatibility shell only** (Filament views/lang/panel bootstrap). Do NOT add new business Models/Services/JS there. See `docs/architecture/SEO_CONTENT_AI_COMPAT_SHELL.md`.
- New agents: start from `docs/architecture/NEW_AGENT_HANDOFF.md`.
- Addons MUST NOT be statically registered in `config/app.php`; active addons are registered dynamically.
- Avoid cross-database foreign keys; use scalar IDs and application-level enforcement.
- `articles` table is Content-owned only. Sibling addons use extension tables (`seo_article_profiles`, `wordpress_article_links`, `article_media_states`, `publishing_article_states`, `seo_content_archive_items`).
- Do NOT restore Article addon compatibility accessors. Do NOT mutate via `window.__seoEditorDomainBridge`.

## Canonical Architecture Rules

- Content Project lifecycle writes MUST go through `ContentProjectCommandBus`.
- Agent/MCP writes MUST go through Gateway, Registry, CommandFactory, and CommandBus; Agent/MCP MUST NOT import models/handlers directly.
- MCP write surface is stricter than Agent write surface; do not expose internal scheduler commands as MCP tools.
- Publishing schedule is owned by SaaS/Laravel. WordPress MUST NOT be treated as schedule source of truth.
- Publishing Queue processing/stuck recovery MUST follow `docs/modules/PUBLISHING.md` and `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`.
- Prompt hooks live under `addons/ai-prompt/resources/prompt-hooks` and resolve via settings binding or task node `prompt_id`.
- Article Editor shell is `addons/content/resources/js/components/SeoArticleEditor.jsx`; domain SoT is Content/Media/SEO/Publishing stores — not mega host useState.
- Extension SDK v1 public contracts are frozen; builtin manifests discover from `addons/agent/src/Extension/Builtin`.
- Architecture refactor wave is **CLOSED**. Manual UI + real WP E2E are verification debt only (`docs/architecture/POST_REFACTOR_MANUAL_CHECKLIST.md`).

## Verification

- Remote-first PHP verification is standard. Prefer:

```text
$PHP_BIN vendor/bin/phpunit --filter=ClassOrMethodName
$PHP_BIN vendor/bin/phpunit --testsuite ContentAddon
$PHP_BIN vendor/bin/phpunit addons/content/tests/Unit
```

- Do not use `php artisan test --filter=...` as the project standard.
- For JS/CSS changes, run or report the relevant frontend check, normally `npm run build`.
- For migration changes, inspect both `up()` and `down()` and verify intended DB connection; do not run migrations unless explicitly asked.
- Protected DBs `omi_channel` / `omi_seo_ai`: only `php artisan refactor:migrate --verify --via-mysql`. Never fresh protected DB.
- If verification is skipped because the task is documentation-only or user forbids execution, report that plainly.

## Skills

- Use `$deploy-diff-workflow` for deploy session, track list, deploy, upload, or deployment requests.
- Use `$remote-test-selection` when choosing backend PHP/frontend verification commands.
- Use `$docs-update-on-xong` when the user says `XONG!`.
- Use `$site-sync-debugging` for Site Sync, WordPress bridge sync, delta, snapshot, queue, or reconcile investigations.
- Use `$cross-repo-contract-change` for backend/plugin API, payload, auth, publishing, article sync, media, updater, or capability contract changes.

## Final Response

- Final responses MUST be concise and in Vietnamese unless the user asks otherwise.
- Include changed files, verification status, deploy tracking status for application-code tasks, and any skipped commands with reason.
