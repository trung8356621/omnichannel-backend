# Article Editor Session Lock (Phase 1 + 1.1)

> Status: Implemented (Phase 1) + Enforcement (Phase 1.1)  
> Task IDs: `article-editor-session-lock-v1`, `article-editor-session-lock-enforcement`  
> Inventory: [`ARTICLE_EDITOR_SEPARATION_INVENTORY.md`](ARTICLE_EDITOR_SEPARATION_INVENTORY.md)  
> Related persistence: [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](ARTICLE_EDITOR_JSON_PERSISTENCE.md)  
> Module SoT: [`../modules/ARTICLE_EDITOR.md`](../modules/ARTICLE_EDITOR.md)

## Authority

- **Server** is sole lock authority (`article_editor_sessions` on `omi_seo_ai`).
- **React** is sole **session-state owner** on the client; Blade/Alpine only **consume** events.
- **localStorage** is recovery cache only (schema v3, user-scoped).
- One **writable** editor session per article at a time.
- Cache lock `ActionSupport::withArticleLock` only serializes acquire/save races — not a collab lock.

## Protocol

| Concern | Value |
|---------|-------|
| Heartbeat | `ARTICLE_EDITOR_HEARTBEAT_SECONDS` (default 30) |
| Lock TTL | `ARTICLE_EDITOR_LOCK_TTL_SECONDS` (default 120) |
| Document version | `articles.document_version` (bigint, default 1) |
| Version bump | Eloquent observer when `body` dirty + conflict guard |

### Endpoints

- `POST /api/seo/articles/{article}/editor-sessions` — acquire
- `PUT .../editor-sessions/{session}/heartbeat`
- `PUT .../editor-sessions/{session}/document` — save/autosave
- `POST .../editor-sessions/{session}/close` — atomic save + release
- `DELETE .../editor-sessions/{session}` — release after ACK only
- `POST .../editor-sessions/takeover` — **deprecated** (manager+ with confirmation). Exclusive UI does not call; keep until product/ops ACK. See [`ARTICLE_EDITOR_LEGACY_CLEANUP.md`](ARTICLE_EDITOR_LEGACY_CLEANUP.md).

### Atomic close

1. authorize + session ownership + active lock  
2. validate `expected_document_version` (+ optional hash)  
3. persist document  
4. release session  
5. commit → ACK → client may redirect  

Failure: no release, no redirect, local draft kept.

### Takeover

Manager/admin (or `User::ROLE_ADMIN` / `ROLE_OWNER`). Revokes old session as `taken_over`. Does **not** merge old local drafts. Takeover **cannot** skip document version conflict.

### Archive

`ArchiveContentProjectService::archive` revokes active sessions for project article ids (`content_project_archived`) so in-flight editors close cleanly. After archive completes, Articles are standalone: `assertArticleEditable` does **not** deny on project `archived_at`; a new acquire/save/Sync WP succeeds without restoring the project or recreating workspace. Historical associations stay on archive items for reports. Restore does **not** restore sessions (`workspace_reused=false` unchanged).

## Session state event schema (Phase 1.1)

- **Name:** `article-editor-session-state-changed`
- **Producer:** React (`emitArticleEditorSessionState` / `EditorSessionClient` via `ArticleEditorWithSession`)
- **Consumers:** Alpine shell Save/Save&Close, Livewire props sync (`editorSessionId`, `expectedDocumentVersion`)
- **Payload:** `{ article_id, session_id, status, writable, document_version, reason_code, lock? }`
- **Statuses:** `acquiring|active|locked|read_only|expired|revoked|taken_over|conflict|closing|released|network_degraded`
- **Reason codes:** use `ArticleEditorSessionErrorCode` / `EDITOR_SESSION_ERROR` constants — never parse Error.message strings. Heartbeat HTTP ≥500 → `article_editor_session_unavailable` (reload CTA, not silent read-only).
- Shell must **not** write session state back
- `expireStaleSessionsForArticleId`: best-effort; InnoDB 1205/1213 retry then `sessions_expire_skipped_lock` — must not fail owning heartbeat/save

## Legacy save

`POST .../save` remains for compatibility but **cannot bypass** an active editor session unless `editor_session_id` / `X-Editor-Session-Id` matches the owning active session. No public `force=true` bypass.

System writers (`article.content.update` from automation/sync) still go through Action bus; body writes bump `document_version` via model observer. Prefer conflict on next heartbeat/save for small deterministic rewrites; block destructive rewrites while locked (media URL rewrite, CP post-image insert, revision restore, external AI apply).

## Frontend

- `EditorSessionClient` (`resources/js/utils/editorSessionClient.js`) — `client_instance_id` in **sessionStorage** (per-tab)
- Session state event: `article-editor-session-state-changed` (`editorSessionState.js`)
- Mount gate `ArticleEditorWithSession` in `article-editor.jsx`: acquire-first; if locked/archived/not_editable **or** session unavailable (5xx heartbeat) → **ExclusiveLockScreen** only (title/body + Reload/Retry; no TipTap / no takeover UI)
- Mid-session `document_version` / content-hash conflict: sync actual version from payload; **keep writable** (toast); do not unmount ExclusiveLockScreen
- Mutation guard: `canMutateEditor` / `assertWritableEditorSession` / `runEditorMutation`
- Shell Alpine consumes session-state event (Save disabled reactive); chrome hidden while exclusive-lock mounted
- Livewire `EditArticle` body writes require `editorSessionId` + `expectedDocumentVersion` and delegate `ArticleEditorPersistService`
- FAQ body apply from editor requires owning session; without session while locked → fail
- Owner unload: `beforeunload` warn when dirty; `pagehide` + sendBeacon release when clean; Save & Close sets `__seoMarkIntentionalEditorClose`
- Server autosave debounced (~4s) with single in-flight + stale ACK guard
- Draft key: `seo-editor:draft:{hash}:{site}:{userId}:{articleId}`

## Phase 1.1 enforcement notes

- React owns session state; shell only consumes.
- User-facing Livewire body path is session-aware (no direct `$record->update(['body'=>...])` in persist).
- Bootstrap hydrate from WP cache skips when any active session exists.
- Media URL rewrite: other sessions blocked; **owning** active session allowed via `assertOwningActiveSessionForMediaMutation` + `editor_session_id` (Fix Slug All).
- System writers still bump `document_version` via observer; conflict surfaces on next heartbeat/save.
- Phase later: Featured/Gallery localStorage SoT cleanup.

## Rollback / deploy

1. Run migrations on `omi_seo_ai` connection.  
2. `npm run build` for article-editor entry.  
3. Feature is always-on once migrated; rollback = revert code + optional drop column/table.

## Known body writers

All `articles.body` writers are content mutations (A). Observer bumps version. Direct SQL bypasses are out of scope blockers.
