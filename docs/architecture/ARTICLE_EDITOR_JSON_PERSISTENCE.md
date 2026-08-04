# Article Editor JSON Persistence (Phase 5A)

> Status: Implemented (dual-write foundation)  
> Task ID: `article-editor-json-persistence-phase-5a`

## Ownership

| Representation | Role |
|----------------|------|
| `articles.editor_document` | **Canonical editable** TipTap envelope JSON |
| `articles.body` | **Derived** HTML for WP / preview / legacy |
| Client `blocks[].content` HTML | Compat until block fully JSON-hydrated |

## Envelope schema

```json
{
  "schema_version": 1,
  "type": "article_document",
  "blocks": [
    { "id": "…", "type": "text", "document": { "type": "doc", "content": […] } },
    { "id": "…", "type": "image", "image": { "src": "…", "alt": "…" } }
  ]
}
```

Matches multi TipTap block UI (Phase 3/4). Not a single flat TipTap doc.

## Columns

- `editor_document` JSON nullable  
- `editor_document_schema_version` uint default 1  
- `editor_document_hash` string(64)  
- `editor_document_status` pending|migrated|failed|manual_review|stale|current  
- `editor_document_updated_at`

## Save flow

1. Session lock + `document_version`  
2. Optional `expected_editor_document_hash`  
3. Validate/normalize JSON (`ArticleEditorDocumentSchema`)  
4. Render HTML (`ArticleEditorDocumentHtmlRenderer`)  
5. Sanitize + FAQ extract  
6. Atomic persist JSON + body  
7. Bump `document_version` (body **or** editor_document dirty)  
8. Revision `seo_meta` stores JSON snapshot  

Client may send `client_rendered_html` for diagnostics only — **server renderer owns `body`**.

## Load / bootstrap

Priority: valid `editor_document` → local draft → HTML ingest.

Normal path: TipTap `setContent(JSON)` — no `htmlDocumentCompat` when JSON present.

## Flags (`config/seo-content-ai.article_editor.json_persistence`)

| Env | Default | Meaning |
|-----|---------|---------|
| `ARTICLE_EDITOR_JSON_PERSISTENCE_ENABLED` | true | Master switch |
| `ARTICLE_EDITOR_JSON_WRITE_DUAL` | true | Write JSON + HTML |
| `ARTICLE_EDITOR_JSON_READ_PREFERRED` | true | Bootstrap prefers JSON |
| `ARTICLE_EDITOR_JSON_PUBLISH_FROM_JSON` | false | Publisher re-render (opt-in later) |

## Rollback

Set `ARTICLE_EDITOR_JSON_READ_PREFERRED=false` → HTML ingest fallback. Keep JSON columns.

## Backfill

```bash
php artisan seo:article-editor-document-backfill --article=9629 --dry-run
php artisan seo:article-editor-document-backfill --limit=100 --only-pending
```

Round-trip mismatch → `manual_review` (no body overwrite).

## Stale contract

Legacy body-only writers (`ArticleContentFaqService`, etc.) set `editor_document_status=stale`.

## Remaining

- Publisher `publish_from_json` default off  
- Full WP import ingest path  
- AI Apply → JSON import adapter  
- Drop accepting HTML-only as editor canonical (later phase)  
- Keep `articles.body` forever in 5A  

## Runtime (Phase 6A)

Editor loads TipTap schema via internal runtime registry wrapping the same `articleEditorExtensions` factories — no schema bump. See [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md).

## Services

`Services/ArticleEditor/Document/*` — Schema, Renderer, Ingest, Writer, RoundTrip, NodeRegistry.

## Legacy cleanup

All JSON persistence flags **retained** (no flag deletion in cleanup). See [ARTICLE_EDITOR_LEGACY_CLEANUP.md](ARTICLE_EDITOR_LEGACY_CLEANUP.md).
