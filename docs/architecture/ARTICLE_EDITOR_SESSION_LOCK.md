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
- `POST .../editor-sessions/takeover` — manager+ with confirmation

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

`ArchiveContentProjectService::archive` revokes active sessions for project article ids. Heartbeat/acquire returns `content_project_archived`. Client → read-only, no reacquire/takeover, draft kept for recovery. Restore does **not** restore sessions (`workspace_reused=false` unchanged).

## Session state event schema (Phase 1.1)

- **Name:** `article-editor-session-state-changed`
- **Producer:** React (`emitArticleEditorSessionState` / `EditorSessionClient` via `ArticleEditorWithSession`)
- **Consumers:** Alpine shell Save/Save&Close, Livewire props sync (`editorSessionId`, `expectedDocumentVersion`)
- **Payload:** `{ article_id, session_id, status, writable, document_version, reason_code, lock? }`
- **Statuses:** `acquiring|active|locked|read_only|expired|revoked|taken_over|conflict|closing|released|network_degraded`
- **Reason codes:** use `ArticleEditorSessionErrorCode` / `EDITOR_SESSION_ERROR` constants — never parse Error.message strings
- Shell must **not** write session state back

## Legacy save

`POST .../save` remains for compatibility but **cannot bypass** an active editor session unless `editor_session_id` / `X-Editor-Session-Id` matches the owning active session. No public `force=true` bypass.

System writers (`article.content.update` from automation/sync) still go through Action bus; body writes bump `document_version` via model observer. Prefer conflict on next heartbeat/save for small deterministic rewrites; block destructive rewrites while locked (media URL rewrite, CP post-image insert, revision restore, external AI apply).

## Frontend

- `EditorSessionClient` (`resources/js/utils/editorSessionClient.js`)
- Session state event: `article-editor-session-state-changed` (`editorSessionState.js`)
- Mount gate `ArticleEditorWithSession` in `article-editor.jsx` (acquire-first; after mount, TipTap stays mounted)
- TipTap `setEditable(false|true)` hard wire from `sessionReadOnly`
- Mutation guard: `canMutateEditor` / `assertWritableEditorSession` / `runEditorMutation`
- Shell Alpine consumes session-state event (Save disabled reactive)
- Livewire `EditArticle` body writes require `editorSessionId` + `expectedDocumentVersion` and delegate `ArticleEditorPersistService`
- FAQ body apply from editor requires owning session; without session while locked → fail
- External revision restore / AI apply / media rewrite blocked while active editor session exists
- Document version conflict: stop autosave, read-only, keep local draft, message for recovery — no auto-merge
- Server autosave debounced (~4s) with single in-flight + stale ACK guard
- Draft key: `seo-editor:draft:{hash}:{site}:{userId}:{articleId}`

## Phase 1.1 enforcement notes

- React owns session state; shell only consumes.
- User-facing Livewire body path is session-aware (no direct `$record->update(['body'=>...])` in persist).
- Bootstrap hydrate from WP cache skips when any active session exists.
- Media URL rewrite: **block** if active editor session (`assertBodyRewriteAllowed`).
- System writers still bump `document_version` via observer; conflict surfaces on next heartbeat/save.
- Phase later: Featured/Gallery localStorage SoT cleanup.

## Rollback / deploy

1. Run migrations on `omi_seo_ai` connection.  
2. `npm run build` for article-editor entry.  
3. Feature is always-on once migrated; rollback = revert code + optional drop column/table.

## Known body writers

All `articles.body` writers are content mutations (A). Observer bumps version. Direct SQL bypasses are out of scope blockers.
