---
name: remote-test-selection
description: "Trigger when selecting tests, build checks, phpunit commands, manual verification, CI, or no-local-test policy for this Laravel backend. Use for PHP/JS verification planning. Do not use to run migrations or deploy."
---

# Purpose

Choose the smallest relevant verification commands for backend, SEO Content AI, and frontend changes.

# Trigger conditions

Use when the user asks for tests, verification, build, PHPUnit, CI, manual verification, or when completing code changes that need a verification report.

# Required context

- Inspect changed files.
- Inspect nearby tests and `docs/operations/TESTING.md`.
- Check `composer.json`, `package.json`, and `phpunit.xml` when commands are uncertain.

# Workflow

- Prefer focused PHPUnit:

```text
$PHP_BIN vendor/bin/phpunit --filter=ClassOrMethodName
$PHP_BIN vendor/bin/phpunit --filter=ClassName::test_method_name
$PHP_BIN vendor/bin/phpunit --testsuite ContentAddon
$PHP_BIN vendor/bin/phpunit addons/content/tests/Unit
$PHP_BIN vendor/bin/phpunit addons/seo/tests/Unit
```

- Do not use `php artisan test --filter=...` as the project default.
- Route feature → owner addon tests (content/media/seo/wordpress/publishing/content-projects/ai-prompt/search-intelligence/site-sync/agent).
- `app/Addons/SeoContentAi/tests` is compatibility-only (`Compat/UsesSeoDatabase`); do not put new tests there.
- For JS/CSS changes, use the relevant frontend check, normally:

```text
npm run build
npm run check:editor-cycles
```

- Architecture handoff: `docs/architecture/NEW_AGENT_HANDOFF.md`.

# Verification

- Report exact commands run and outcome.
- If commands are not run because user forbids local execution or task is docs-only, report the manual verification command instead.

# Safety and approval boundaries

- MUST NOT install dependencies.
- MUST NOT run migrations.
- MUST NOT alter database state.
- MUST NOT treat `php artisan test` as standard unless the user explicitly asks.

# Expected final report

- Files changed.
- Commands run or recommended.
- Any skipped command and reason.
