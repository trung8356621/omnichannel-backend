# Codex Project Instructions

## Repository Role

- This repository is the canonical Laravel SaaS backend for the Omnichannel workspace.
- Stack: Laravel 12, PHP 8.2+, Filament 3, React, Vite, Tailwind CSS, MySQL.
- It owns canonical business workflows for sites, services, subscriptions, wallets, payments, orders, invoices, SEO Content AI, publishing, site sync, Agent/MCP, and plugin update delivery.
- The sibling WordPress plugin lives at `..\wp-seo-ai`. For REST/API/auth/site-sync/publishing/article/media/capability contract work, Codex MUST inspect both repositories.

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
- SEO Content AI uses runtime connection `omi_seo_ai`, bootstrapped by `SeoDatabaseConnectionService` from core table `seo_database_connections`; it does not use addon static DB config.
- Features that can be isolated MUST live under `app/Addons/{PascalCaseName}/`.
- Addons MUST NOT be statically registered in `config/app.php`; active addons are registered dynamically.
- Avoid cross-database foreign keys; use scalar IDs and application-level enforcement.

## Canonical Architecture Rules

- Content Project lifecycle writes MUST go through `ContentProjectCommandBus`.
- Agent/MCP writes MUST go through Gateway, Registry, CommandFactory, and CommandBus; Agent/MCP MUST NOT import models/handlers directly.
- MCP write surface is stricter than Agent write surface; do not expose internal scheduler commands as MCP tools.
- Publishing schedule is owned by SaaS/Laravel. WordPress MUST NOT be treated as schedule source of truth.
- Publishing Queue processing/stuck recovery MUST follow `docs/modules/PUBLISHING.md` and `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`.
- Prompt hooks MUST resolve via settings binding or task node `prompt_id`; do not select prompts by legacy `is_active` alone.
- Article Editor changes MUST preserve session lock, document version, TipTap JSON document model, command layer, media snapshot ownership, and public/internal SDK boundaries.
- Extension SDK v1 public contracts are frozen; breaking changes REQUIRE a new ADR and SDK major bump.

## Verification

- Remote-first PHP verification is standard. Prefer:

```text
$PHP_BIN vendor/bin/phpunit --filter=ClassOrMethodName
$PHP_BIN vendor/bin/phpunit app/Addons/SeoContentAi/tests/Unit
```

- Do not use `php artisan test --filter=...` as the project standard.
- For JS/CSS changes, run or report the relevant build/check, normally `npm run build`.
- For migration changes, inspect both `up()` and `down()` and verify intended DB connection; do not run migrations unless explicitly asked.
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
