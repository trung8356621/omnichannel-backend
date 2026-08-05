# Cursor Migration Map - Backend

| Cursor source | Codex destination | Status | Notes |
|---|---|---|---|
| `AGENTS.md` | `AGENTS.md` | migrated | Rewritten as short always-on Codex guardrails. |
| `.cursorrules` | `AGENTS.md` | merged | Laravel, addon, PHP, Filament, DB, frontend, WP bridge, verification rules merged. Old `php artisan test` guidance superseded. |
| `.clinerules` | `AGENTS.md` | merged | Duplicate of `.cursorrules`; retained only non-conflicting rules. |
| `.cursorignore` | `.agents/cursor-migration-map.md` | skipped-duplicate | Ignore patterns are tool-specific; no Codex behavior needed beyond not scanning vendor/node_modules/logs. |
| `.cursor/settings.json` | `.agents/cursor-migration-map.md` | needs-review | Redis-development setting is Cursor/plugin-specific, no Codex-native equivalent created. |
| `.cursor/rules/auto-update-docs.mdc` | `.agents/skills/docs-update-on-xong/SKILL.md` | migrated | Triggered workflow moved to skill; archive/MAP references normalized to canonical docs. |
| `.cursor/rules/caveman.mdc` | `.agents/cursor-migration-map.md` | skipped-obsolete | Style mode not migrated into project rules; conflicts with clear Vietnamese project communication. |
| `.cursor/rules/diff-start.mdc` | `AGENTS.md`; `.agents/skills/deploy-diff-workflow/SKILL.md` | migrated | Corrected against real script: `-Id` and `-Path` are required; `-Current` is obsolete. |
| `.cursor/rules/dont-test.mdc` | `AGENTS.md`; `.agents/skills/remote-test-selection/SKILL.md` | merged | Remote-first/no-local-default retained; conflicts with current AGENTS "run when practical" resolved in favor of explicit remote/manual verification. |
| `.cursor/rules/mcp-rule.mdc` | `AGENTS.md` | merged | Context hygiene retained as "inspect relevant implementation/tests/docs"; no MCP-specific file reader exists in Codex. |
| `.cursor/rules/modal-alpine.mdc` | `AGENTS.md` | merged | Always-on UI guardrail retained. |
| `.cursor/rules/no-auto-ftp-upload.mdc` | `AGENTS.md`; `.agents/skills/deploy-diff-workflow/SKILL.md` | migrated | No FTP/SFTP/upload unless explicit user request. |
| `.cursor/rules/phpunit-remote.mdc` | `AGENTS.md`; `.agents/skills/remote-test-selection/SKILL.md` | migrated | `$PHP_BIN vendor/bin/phpunit` convention retained. |
| `.cursor/rules/web-app-logging.mdc` | `AGENTS.md` | migrated | HTTP logging guardrail retained; old MAP doc refs replaced with canonical operations docs. |
| `.cursor/rules/x-select.mdc` | `AGENTS.md` | migrated | Blade `<x-select>` and SEO React `SeoSelect` retained. |
| `.cursor/commands/**/*.md` | `.agents/cursor-migration-map.md` | skipped-obsolete | Directory not present in repo. |
| `.cursor/skills/**` | `.agents/cursor-migration-map.md` | skipped-obsolete | Directory not present in repo. |
| `.cursor/plans/*.plan.md` | `.agents/cursor-migration-map.md` | skipped-obsolete | Cursor task plans are historical task artifacts, not always-on rules, commands, or reusable skills. |
| `README.md` | `AGENTS.md` | merged | Repo role and docs index retained. |
| `docs/README.md` | `AGENTS.md` | migrated | Canonical doc precedence retained. |
| `docs/architecture/*` | `AGENTS.md`; skills | merged | Only index-level architecture guardrails retained; details remain canonical docs. |
| `docs/modules/*` | `AGENTS.md`; skills | merged | Module docs referenced, not copied. |
| `docs/contracts/*` | `AGENTS.md`; skills | merged | Contract guardrails retained. |
| `docs/operations/*` | `AGENTS.md`; skills | merged | Testing/deploy/scheduler guidance retained by reference. |
| `composer.json` | `AGENTS.md`; `.agents/skills/remote-test-selection/SKILL.md` | merged | PHP/Laravel/PHPUnit commands verified. |
| `package.json` | `AGENTS.md`; `.agents/skills/remote-test-selection/SKILL.md` | merged | Vite/build/check commands verified. |
| `phpunit.xml` | `.agents/skills/remote-test-selection/SKILL.md` | merged | Test discovery paths verified. |
| `.secure/deploy-diff.ps1` | `AGENTS.md`; `.agents/skills/deploy-diff-workflow/SKILL.md` | migrated | Read allowed sections; real syntax recorded; plugin gap recorded. |
| `routes/*`, `app/Addons/SeoContentAi/routes/api.php`, `SeoPanelProvider.php` | `AGENTS.md`; `.agents/skills/cross-repo-contract-change/SKILL.md`; `.agents/skills/site-sync-debugging/SKILL.md` | merged | Used to verify API/MCP/WP bridge surfaces. |

## Conflict Log

| Source A | Source B | Decision | Reason | User review |
|---|---|---|---|---|
| `.cursor/rules/diff-start.mdc` uses `-Current` and omits `-Id` for track/deploy | `.secure/deploy-diff.ps1` requires `-Id`, `track` requires `-Path`, no `-Current` | Use real script syntax | Runtime/script wins. | No |
| `.cursor/rules/dont-test.mdc` forbids local tests/build | Previous `AGENTS.md` said run Pint/build when practical | Remote-first/manual verification is required unless user explicitly asks to run | Cursor rule is more specific and matches operations docs. | No |
| Plugin `.cursorrules` requires automatic version bump | User migration request says do not bump plugin version automatically | Do not auto bump/package/release | Latest user safety rule wins; plugin AGENTS records explicit-release only. | No |
| Archive/MAP references in old Cursor docs | `docs/README.md` says archive is historical only | Use canonical docs only | Canonical index wins. | No |
