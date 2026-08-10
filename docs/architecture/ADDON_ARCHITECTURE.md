# Addon Architecture (authoritative)

> Status: Canonical after Task 9 refactor closure  
> Last verified: 2026-08-10  
> Handoff: [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md) · Shell: [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md)  
> Column map: [ARTICLE_COLUMN_OWNERSHIP.json](ARTICLE_COLUMN_OWNERSHIP.json)

## Core

**Core = protocol / runtime only.**

Core owns identity, tenancy, billing, addon discovery/registry, SEO DB credential rows, shared HTTP/logging/queue plumbing, and frontend **transport/runtime** (e.g. `resources/js/core/saveCoordinator.js`).  
Core does **not** own SEO/content business models, article domain state, or peer-addon Filament product pages.

## Peer addons

All product domains live under `/addons/{slug}` as **peers** (no parent/child hierarchy):

| Slug | Role (short) |
|------|----------------|
| `search-foundation` | SEO DB connection bootstrap, shared search infra |
| `seo` | SEO score/meta, audit, GlobalSeoBar, SEO editor domain |
| `search-intelligence` | GSC / Performance Hub |
| `ai-prompt` | Prompts, prompt hooks, AI result UX |
| `content` | Articles table, editor document, ArticleEditorView |
| `content-projects` | Projects/tasks, archive mirror |
| `media` | Media library, featured/gallery, media editor domain |
| `wordpress` | WP bridge write/sync, wordpress_article_links |
| `publishing` | Publish queue/schedule, publishing_article_states |
| `site-sync` | Site Sync v2 orchestration |
| `agent` | Agent/MCP, Extension Builtin discovery |
| `social` | Social domain |
| `commerce` | Commerce domain |

## SeoContentAi = compatibility only

`app/Addons/SeoContentAi/` is a **compatibility shell** (Filament view namespace, panel bootstrap, config/lang merge, route shim).  
**No new business code** there. See [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md).

Active business code lives in `/addons/*`.

## Hard rules (1–20)

1. All addons are peers.
2. No parent/child addon hierarchy.
3. Addon cannot add business columns to another addon's table.
4. Cross-addon communication: capability / command / event / stable DTO.
5. No sibling implementation imports.
6. `articles` belongs Content only.
7. `article_meta` is sparse metadata only.
8. SEO Article state → `seo_article_profiles`.
9. WP Article state → `wordpress_article_links`.
10. Media Article state → `article_media_states` / canonical media relation.
11. Publishing → `publishing_article_states`.
12. Project archive → `seo_content_archive_items`.
13. React state has one owner.
14. No `useEffect` business workflows.
15. Core frontend owns transport/runtime only.
16. Global Save is SaveCoordinator orchestration.
17. missing payload field = untouched.
18. `null` = intentional clear.
19. value = set/update.
20. SeoContentAi is compatibility-only; no new business code.

## Canonical handoff for new agents

Start here:

1. [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md) — where to look, SoT maps, do-nots, first commands  
2. [SEO_CONTENT_AI_COMPAT_SHELL.md](SEO_CONTENT_AI_COMPAT_SHELL.md) — what remains under SeoContentAi and why  
3. [POST_REFACTOR_MANUAL_CHECKLIST.md](POST_REFACTOR_MANUAL_CHECKLIST.md) — USER browser / WP verification debt  

**Architecture refactor is CLOSED.** No Task 10 redesign wave.
