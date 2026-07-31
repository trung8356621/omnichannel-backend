> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Skill: Add Automation Action (SeoContentAi)

HÆ°á»›ng dáº«n Cursor/dev thÃªm Business Action má»›i. Path gá»‘c: `app/Addons/SeoContentAi/Automation/`.

## Khi nÃ o cáº§n Action

Cáº§n Action khi:

- Workflow / Rule / automation node sáº½ gá»i capability theo **action key** (khÃ´ng gá»i PHP class).
- CÃ³ use case automation tháº­t (khÃ´ng â€œbá»c cho Ä‘á»§ catalogâ€).
- Semantic rÃµ, side effect Ä‘Ã£ trace, permission + idempotency xÃ¡c Ä‘á»‹nh Ä‘Æ°á»£c.

KhÃ´ng cáº§n Action khi:

- Chá»‰ chuáº©n bá»‹ `ActionContext` â†’ giá»¯ `AutomationSiteContextResolver` (`INTERNAL_SERVICE_ONLY`).
- Chá»‰ orchestration nhiá»u action â†’ composition workflow sau nÃ y, khÃ´ng bá»c Orchestrator mÃ¹.
- Capability nguy hiá»ƒm / chÆ°a cÃ³ domain semantic â†’ `CATALOG_ONLY` hoáº·c `BLOCKED`.

## Trace side effect

TrÆ°á»›c khi code handler:

1. Äá»c inventory `docs/automation/AUTOMATION_SERVICE_INVENTORY.md`.
2. Grep caller tháº­t (Filament, Job, Observer, Listener, Workflow node).
3. Ghi: write DB nÃ o, queue nÃ o, HTTP outbound nÃ o, event nÃ o.
4. XÃ¡c nháº­n khÃ´ng giáº¥u WP outbound trong Article/Project/SEO/Keyword action.

## Naming

| Loáº¡i | Pattern | VÃ­ dá»¥ |
|---|---|---|
| Action | `<module>.<resourceâ€¦>.<verb>` | `article.content.update` |
| Event | `<module>.<past_tense_phrase>` | `article.content_updated` |

Module WP outbound luÃ´n `wordpress.*`. Cáº¥m alias cÃ¹ng nghÄ©a.

## Canonical IDs

Chá»‰ dÃ¹ng: `team_id?`, `site_id`, `connection_id`, `article_id`, `wp_post_id`.

Cáº¥m trong context: `website_id`, `domain_id`. Normalize qua `CanonicalIds`.

## ActionDefinition

ÄÄƒng kÃ½:

1. Metadata trong `Registry/ActionCatalogBootstrap.php` (luÃ´n cÃ³).
2. Handler class implement `Contracts/BusinessAction` náº¿u `IMPLEMENT`.
3. ÄÄƒng kÃ½ handler trong `Registry/ActionHandlerRegistrar.php`.

Field báº¯t buá»™c quan tÃ¢m: `key`, `module`, `sideEffect`, `riskLevel`, `selectability`, `inputSchema`, `outputSchema`, `idempotent`, `lockScope`, `supportsDryRun`, `emittedEvents`, `impliesPublishStatus` (WP).

**Catalog definition pháº£i khá»›p handler** (Ä‘áº·c biá»‡t `supportsDryRun`, schema). Handler definition tháº¯ng khi `registerHandler`.

## Input / output

- Input: scalar/array á»•n Ä‘á»‹nh; validate qua Registry schema.
- Output: DTO/`ActionResult` array â€” **khÃ´ng** Eloquent Model.
- Write action nÃªn khai bÃ¡o: `changed`, `changed_fields?`, `entity_id`, `status?`, `revision?`, `deduplicated?`.

## Permission

- `ActionSupport::assertMutable` / site scope / policy hiá»‡n cÃ³.
- Wrong `site_id` / actor â†’ reject, khÃ´ng silent write.

## Idempotency

| Pattern | CÃ¡ch lÃ m |
|---|---|
| Create | Business-source key tháº­t (khÃ´ng chá»‰ title). KhÃ´ng cÃ³ key â†’ ghi limitation, `idempotent: false`. |
| Update | Prefer `expected_revision` náº¿u domain cÃ³ revision. ChÆ°a cÃ³ â†’ limitation, khÃ´ng giáº£ láº­p. |
| Assign / pivot | Dedup theo identity tháº­t; retry khÃ´ng táº¡o link trÃ¹ng; set `deduplicated`. |
| No-op | KhÃ´ng emit event `*.created` / `*_updated` khi khÃ´ng Ä‘á»•i. |

## Lock / transaction

- `lockScope` khá»›p entity (`article`, `project_task`, â€¦).
- Write domain SEO trong `DB::connection('omi_seo_ai')->transaction()` khi multi-write.
- Persist automation runtime (rule/execution/event) qua model `AutomationModel` / `AutomationConnection::db()` â€” **khÃ´ng** hard-code `omi_seo_ai`.
- Emit event **sau** commit (Runner dispatch khi `success`).

## Event

- DÃ¹ng `EventEnvelope` + key trong event catalog.
- KhÃ´ng phÃ¡t event khi dry-run / dedup no-op / failure.

## Tests (báº¯t buá»™c cháº¡y)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=Automation
```

Checklist test:

- [ ] Foundation/catalog unique keys
- [ ] Handler contract + selectability
- [ ] Legacy / WP non-selectable blocked tá»« workflow origin
- [ ] PublishIntent cho publish
- [ ] Redaction
- [ ] Boundary: khÃ´ng WP outbound / khÃ´ng Filament Resource trong Action module local
- [ ] Idempotency / wrong site / permission / serializable output
- [ ] No-op khÃ´ng emit event sai

## Docs cáº­p nháº­t

- `AUTOMATION_ACTION_CATALOG.md` â€” classification, selectability, test status, migrated, remaining risk
- `AUTOMATION_EVENT_CATALOG.md` â€” event má»›i
- `AUTOMATION_SERVICE_INVENTORY.md` â€” map service â†’ action
- `AUTOMATION_BOUNDARIES.md` â€” náº¿u Ä‘á»•i boundary
- `AUTOMATION_MIGRATION_STATUS.md` â€” káº¿t quáº£ test tháº­t
- `AUTOMATION_PHASE3_BLOCKERS.md` â€” náº¿u BLOCKED

## Blocker policy

ÄÆ°a `BLOCKED` khi:

- KhÃ´ng cÃ³ domain service Ä‘Ãºng semantic.
- Side effect nguy hiá»ƒm chÆ°a tÃ¡ch (vd. xÃ³a media + clear WP queue).
- Cáº§n boolean flag Ä‘á»ƒ che behavior.

KhÃ´ng â€œfix assertionâ€ Ä‘á»ƒ há»£p thá»©c hÃ³a bug.

## Checklist trÆ°á»›c selectable

- [ ] Handler cháº¡y Ä‘Æ°á»£c + test pass
- [ ] Side effect documented
- [ ] Idempotency + lock
- [ ] KhÃ´ng Filament Resource/Page dependency
- [ ] KhÃ´ng WP outbound (trá»« module `wordpress`)
- [ ] `selectability = selectable` cÃ³ chá»§ Ä‘Ã­ch

## Checklist trÆ°á»›c migrate caller

- [ ] Phase adapter stable
- [ ] Production path parity verified
- [ ] Feature flag / dual-run náº¿u rá»§i ro cao
- [ ] Cáº­p nháº­t migration table `Migrated = yes`
- [ ] KhÃ´ng migrate lÃ©n trong Phase catalog-only

## Cáº¥m

- Bá»c mÃ¹ Orchestrator / `ArticleEditorSyncOrchestrator` vÃ o Article local action.
- Expose `wordpress.article.publish` / `comment_review.publish` cho Rule UI.
- Táº¡o `project.run_everything` / `project.process`.
- DÃ¹ng `markArticleReviewed` cho `article.review.request`.
