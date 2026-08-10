# New Agent Handoff (post-refactor)

> Status: Canonical operational handoff  
> Last verified: 2026-08-10  
> Authority: [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · Shell: [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md)  
> Manual debt: [POST_REFACTOR_MANUAL_CHECKLIST.md](POST_REFACTOR_MANUAL_CHECKLIST.md)

**Do not inherit SeoContentAi-monolith assumptions.** Architecture refactor is **CLOSED**.

---

## A. What changed

Old `App\Addons\SeoContentAi` monolith was split into **peer addons** under `/addons/*`.

- Core = protocol/runtime only  
- Business PHP + addon DB migrations live in owner addons  
- Addon columns dropped from `articles`; extension tables are SoT  
- React `SeoArticleEditor` reduced (~11.3k → ~1.9k); canonical business `useState` cut hard  
- Active SeoContentAi JS = **0**  
- Article addon facade accessors removed  
- SaveCoordinator owner semantics live; sectioned `ArticleEditorView` exists  
- `window` domain bridge = **diagnostics-only** (no business mutations)

SeoContentAi remains a **compatibility shell** only.

---

## B. Where to look (feature → owner path)

| Feature | Owner path |
|---------|------------|
| Article / editor bug | `addons/content` |
| Featured / gallery | `addons/media` |
| WP sync / bridge | `addons/wordpress` |
| Publishing schedule/queue | `addons/publishing` |
| SEO scoring / audit | `addons/seo` |
| Performance Hub / GSC | `addons/search-intelligence` |
| Content Project | `addons/content-projects` |
| Prompt / provider | `addons/ai-prompt` |
| Site Sync | `addons/site-sync` |
| Agent / MCP | `addons/agent` |
| SEO DB connection bootstrap | `addons/search-foundation` |
| Save transport / SaveCoordinator core | `resources/js/core/saveCoordinator.js` (+ content runtime re-export) |
| Filament Blade still `seo-content-ai::` | shell views — see compat shell doc; **do not add business there** |

Column ownership detail: [ARTICLE_COLUMN_OWNERSHIP.json](ARTICLE_COLUMN_OWNERSHIP.json).

---

## C. Database

| Kind | Names / command |
|------|-----------------|
| **Protected** | `omi_channel`, `omi_seo_ai` — never destroy / never `fresh` |
| **Non-destructive verify** | `php artisan refactor:migrate --verify --via-mysql` (safe to re-run; expect idempotent) |
| **Disposable** | `*_test` DBs only |
| **Fresh test DB** | only with `--confirm-destroy-test-db` |

Do **not** run destructive fresh against protected DBs.

SEO runtime connection name: `omi_seo_ai` (bootstrapped from core `seo_database_connections` via Search Foundation).

---

## D. Article SoT (tables)

| Concern | SoT table / owner |
|---------|-------------------|
| Document (title, slug, body, `editor_document`, …) | `articles` — **Content** only |
| Sparse misc meta | `article_meta` — sparse only |
| SEO article state | `seo_article_profiles` |
| WP article state | `wordpress_article_links` |
| Media article state | `article_media_states` (+ canonical media relation) |
| Publishing state | `publishing_article_states` |
| Project archive | `seo_content_archive_items` |

Sibling addons **must not** add business columns to `articles`.

---

## E. React SoT

| Domain | Path |
|--------|------|
| Content facts / document | `addons/content/resources/js/editor/domains/content/` |
| Media facts | `addons/media/resources/js/editor/domains/media/` |
| SEO facts | `addons/seo/resources/js/editor/domains/seo/` |
| Publishing facts | `addons/publishing/resources/js/editor/domains/publishing/` |
| WordPress facts | `addons/wordpress/resources/js/editor/domains/wordpress/` |
| Host / save owners registration | `addons/content/resources/js/editor/domains/registerDomainSaveOwners.js` |
| Shell entry | `addons/content/resources/js/article-editor.jsx`, `SeoArticleEditor.jsx` |
| Server sectioned view | `addons/content/src/Services/ArticleEditorView.php` |

One React state owner per fact. Global Save = SaveCoordinator. Payload: missing = untouched; `null` = clear; value = set.

---

## F. Important do-nots

- Don’t add columns to `articles` from a sibling addon  
- Don’t restore Article compatibility accessors / facade  
- Don’t add business code to SeoContentAi  
- Don’t use `window` bridge for mutations (diagnostics only)  
- Don’t use `useEffect` for business workflow  
- Don’t build mega mutation payloads  
- Don’t `fresh` protected DB  
- Don’t redesign architecture / invent new stores or capabilities for aesthetics  

---

## G. Current known verification debt

Documented debt — **do not** start another architecture wave for these:

| Item | Status |
|------|--------|
| Manual browser regression | **PENDING** (USER checklist) |
| Real WP E2E | **PENDING** — no local WP runtime in this environment |
| Key contracts (Ownership, WP harness, Publishing invariants, ExtensionCutover, RuntimeLogger, SaveCoordinator) | **PASS** this session |
| Broad addon suites | Residual **B** failures: stale test FQCN / Mockery / env (sqlite vs SEO MySQL). Fix test config when touching that area — **not** architecture |
| `WordPressPermalinkBuilderTest` | Residual Mockery/siteInfo errors — class **B/C** |
| Codebase-memory | Fast reindex done (`D-work-omnichannel-backend`); still re-check symbols against disk before trusting old paths |

Closure report: `docs/architecture/TASK9_CLOSURE_REPORT.json`

---

## H. First commands for a new agent

Safe, non-destructive starting set (remote-first; do not use `php artisan test` as project standard):

```text
composer dump-autoload -o

npm run build

node --test resources/js/core/__tests__/saveCoordinator.test.mjs

php artisan refactor:migrate --verify --via-mysql
php artisan refactor:migrate --verify --via-mysql

$PHP_BIN vendor/bin/phpunit --filter=ArticleExtensionOwnershipContractTest
$PHP_BIN vendor/bin/phpunit --testsuite ContentAddon
```

Then read:

1. `docs/architecture/ADDON_ARCHITECTURE.md`  
2. `docs/architecture/SEO_CONTENT_AI_COMPAT_SHELL.md`  
3. `docs/architecture/POST_REFACTOR_MANUAL_CHECKLIST.md`  
4. Relevant `docs/modules/*` for the feature under change  

Deploy tracking (backend only): `.secure/deploy-diff.ps1` with a kebab-case `-Id`. Plugin repo `wp-seo-ai` is **not** covered by that script.
