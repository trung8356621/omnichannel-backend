# Site Sync

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/SITE_SYNC_V2.md`, `docs/SITE_SYNC_V2_*.md`, `docs/WP_PLUGIN_SITE_SYNC_V2.md` (Site Sync sections), `docs/archive/site-sync/*`

## 1. Purpose

Site Sync keeps Laravel’s SEO catalog aligned with live WordPress public post/SEO/link data.

- **WordPress** = source of truth for public posts, provider SEO metadata, permalinks, taxonomy, provider keywords/scores.
- **Laravel** = catalog, ownership layers, ops UI, agent context, workspace fallbacks.

Not the same as article **Publish** (Content Project / editor outbound) or Domain Settings **Save**.

## 2. Canonical routes

| Entry | Path / surface | Notes |
|-------|----------------|-------|
| Domain Sync UI | Domain Resource → General → **Đồng bộ & kiểm tra website** | One primary button; bootstrap preview if not stamped |
| Ops (primary) | `/seo/{connection_hash}/content-operations` tab **Site Sync** | `ContentProjectOperationsCenter` — runs, inbound events, resume/cancel/reconcile/diagnostic via CommandBus |
| Ops page (hidden nav) | `/seo/{connection_hash}/site-sync-operations` | `SiteSyncOperationsCenter` — `shouldRegisterNavigation(): false` |
| Bridge inbound | `POST /api/seo-wp-bridge/delta-event` | Fast ack + queue (`site_sync.v1` outbox) |
| Bridge compat | `POST /api/seo-wp-bridge/snapshot-callback` | Snapshot/delta apply compat path |
| Bridge legacy push | `POST /api/seo-wp-bridge/push-content` | Compat import; **V2 writer skips** links/keywords/scores enrich |
| WP REST (pull) | `/wp-json/omi-seo-ai/v1/capabilities`, `/sync/v2/{profile,delta,batches,manifest}` | Plugin ≥ `SiteSyncSchema::MIN_BRIDGE_VERSION` (`1.0.64`) |
| CLI | `php artisan seo:site-sync {site_id}` | Optional `--snapshot`, `--sync` |
| CLI reconcile | `php artisan seo:site-sync-reconcile` | `--mode=quick\|standard\|full_rebuild` |
| CLI backfill | `php artisan seo:site-sync-v2-backfill {site_id}` | `--dry-run` / `--execute` |

Auth for bridge callbacks: Bearer `sites.seo_read_token` (+ site/domain binding). Middleware group `api` (no session).

## 3. Main components

| Component | Path | Role |
|-----------|------|------|
| Schema | `Services/SiteSync/Contracts/SiteSyncSchema.php` | `site_sync.v1`, steps, sources, min bridge |
| Orchestrator | `RunSiteSyncOrchestrator` + `SiteSyncStepRunner` | Creates run, dispatches step jobs |
| Step job | `Jobs/SiteSync/ProcessSiteSyncStepJob` | Unique per `runId`; queue `seo` |
| Inbound gateway | `SiteSyncInboundGateway` | HTTP snapshot/compat push |
| Delta ingest | `SiteSyncDeltaEventIngestor` | Inbox → process |
| Inbound job | `ProcessSiteSyncInboundEventJob` | Unique per `eventId`; queue `seo` |
| Client | `WordPressSiteSyncClient` | Pull capabilities / profile / delta / batches / manifest |
| Ownership | `SiteSyncOwnershipResolver` | Manual > Provider > Workspace |
| Bootstrap | `SiteSyncBootstrapService` | Preview + first-time snapshot |
| Backfill | `SiteSyncV2BackfillService` | Legacy migrate (non-destructive) |
| Handshake / security | `SiteSyncHandshakeService`, `SiteSyncCallbackVerifier` | HMAC + nonce/replay |
| Cutover | `SiteSyncCutoverStateService` + cutover commands | Writer mode / shadow / rollback flags |
| Flags | `SiteSyncFeatureFlags` | `config('seo-content-ai.seo_architecture.site_sync_v2.*')` |
| Handler | `SiteSyncCommandHandler` / `SiteSyncCutoverCommandHandler` | CommandBus |
| Presenters | `SiteSyncStatusPresenter`, `SiteSyncSourceLabelPresenter` | Ops / Domain UI |
| WP outbox | `wp-seo-ai` `Site_Sync_Outbox` | Debounced auto delta → Laravel callback |
| Capability | `wp-seo-ai` `Capability_Manifest` | Provider adapters → manifest |
| Scores | `wp-seo-ai` `Score_Exporter` | Provider score export in batches |

## 4. Data ownership

Connection: `omi_seo_ai` (Site Sync tables) + core `sites` / site metas for tokens & stamps.

| Concern | Owner | Notes |
|---------|-------|-------|
| Public post body/title/status/permalink/taxonomy/featured | WordPress | Catalog mirrors; WP authoritative fields in `SiteSyncOwnershipResolver::WP_AUTHORITATIVE` |
| Provider SEO meta / focus KW / score | WordPress provider adapters | Via capability manifest — Laravel does not hardcode provider gaps |
| Tone / CTA / contact override / short-desc override / manual links / manual KW | Manual (Laravel) | Highest priority; never destroyed by sync |
| Workspace KW / score / link-health fallbacks | Workspace | Used when provider capability missing |
| Effective keyword | Resolved | Priority `manual` > `provider` > `workspace` (`SiteSyncSchema::KEYWORD_PRIORITY`) |
| Bootstrap stamp | Site meta `seo_site_sync_v2_bootstrapped_at` | First successful bootstrap finalize |
| Handshake | Site meta `seo_site_sync_v2_handshake` | Health for signed callbacks |

Lower sources are **kept**; effective value resolved separately (no silent overwrite of Manual).

## 5. Read path

```
UI Ops / Domain status / Agent site.* reads
  → SiteSyncStatusPresenter / diagnostic services
  → Site metas + seo_site_sync_* tables + capability cache

Pull from WP (orchestrator / reconcile):
  WordPressSiteSyncClient
    → GET capabilities | sync/v2/profile | delta | manifest
    → POST sync/v2/batches
```

Reconcile scheduler scans sites with `seo_read_token` meta (`whereHas(metas…)`), **not** a non-existent `sites.settings` column.

## 6. Write path

### Save vs Sync vs Publish vs Rebuild

| Action | Behavior |
|--------|----------|
| **Save Domain Settings** | Persist tone/CTA/short-desc/manual links only. No keyword sync job, no HTML full-site parse, no Site Sync run. |
| **Sync** | `site.sync` → `RunSiteSync` orchestrator (incremental/standard by default). |
| **Auto delta** | WP outbox → signed `delta-event` → inbound inbox → `ProcessSiteSyncInboundEventJob` → reconcile one post. |
| **Publish** | Content Project / editor WordPress publish path — **separate** module (`WORDPRESS_BRIDGE` / Content Projects). |
| **Force full / rebuild** | Explicit Advanced/CLI (`site.sync.force_full` / reconcile `full_rebuild`); Agent requires confirmation where gated. |

### RunSiteSync orchestrator steps

Canonical list: `SiteSyncSchema::ORCHESTRATOR_STEPS` (9 entries; older docs said “8-step” loosely):

1. `detect_capability`
2. `request_snapshot_delta`
3. `sync_site_profile`
4. `sync_url_catalog`
5. `sync_provider_keywords`
6. `missing_capability_fallback`
7. `validate_changed_links`
8. `score_missing_articles`
9. `finalize`

Modes: `snapshot` | `delta` | `force_full` (`SiteSyncSchema`).

### Inbound outbox callback

1. WP hooks debounce → `omi_seo_sync_outbox`
2. Flush cron `omi_seo_ai_flush_sync_outbox` (overlap lock)
3. Laravel `delta-event` verifies signature/binding → status `received`…
4. Queue job processes → catalog reconcile
5. Loop prevention: `_omi_seo_ai_skip_push` / `Laravel_Push_Sync::is_suppressed()`

Inbound statuses: `received` → `validated` → `queued` → `processing` → `completed`; terminal `failed` | `dead_letter` | `ignored_duplicate` | `ignored_stale`.

## 7. Public capabilities

Registered on `ContentProjectCapabilityRegistry` / CommandBus (site domain), including:

| Capability | Command |
|------------|---------|
| `site.sync` | `RunSiteSyncCommand` |
| `site.sync.force_full` | `ForceFullSiteSyncCommand` |
| `site.sync_keywords` / `site.sync_links` | Targeted sync |
| `site.resume_sync` / `site.retry_sync_step` / `site.cancel_sync` | Run control |
| `site.reconcile` | Drift reconcile |
| `site.requeue_inbound_event` | Dead-letter recovery |
| `site.preview_bootstrap` / `site.bootstrap` | First-time |
| `site.backfill_v2` | Legacy backfill |
| `site.validate_handshake` / `site.generate_diagnostic` | Health |
| Cutover set | `preview_cutover`, `enter_shadow`, `activate_v2`, `rollback_legacy`, comparison/repair |

Agent CLI: `/site-sync`, `/site-sync-keywords`, `/site-sync-links`. `site.sync` **never** activates cutover.

## 8. Internal-only capabilities

| Surface | Rule |
|---------|------|
| `ProcessSiteSyncStepJob` / inbound job internals | Queue workers only |
| Staging writers / batch reconcilers | Called from step runner |
| Compat `push-content` enrich for V2 writers | Skipped — delta/snapshot own links/keywords/scores |
| Emergency rollback flag | Ops/config — disables V2 writer path |
| Shadow / dual-run comparator | Cutover tooling, not default Sync button |

## 9. Authorization and confirmation

- Bridge: Bearer `seo_read_token` + site binding; optional HMAC when `require_signed_callbacks` (production should enforce).
- Filament: SEO panel access + site scope (`SeoAccessControl`).
- Bootstrap first-time: UI preview → confirm → `site.bootstrap`.
- Cancel sync: no rollback of already reconciled data.
- Destructive cutover / rollback / force full: confirmation / CLI explicit flags.
- Agent: high-risk site ops follow Gateway confirmation policy; sync ≠ cutover activation.

## 10. Queue and scheduler ownership

| Owner | What |
|-------|------|
| Queue `seo` | `ProcessSiteSyncStepJob`, `ProcessSiteSyncInboundEventJob` (`ArticleWpSyncQueueService::QUEUE_NAME`) |
| Unique keys | `site-sync-step:{runId}`, `site-sync-inbound-event:{eventId}` |
| Scheduler | Hourly `seo-content-ai:site-sync-reconcile-quick` → `seo:site-sync-reconcile --mode=quick --limit=30` (`withoutOverlapping(50)`) |
| Site lock | `SiteSyncLockService` — skip reconcile when sync run holds lock |
| V2 writer gate | `SiteSyncCutoverStateService::isV2Writer` — non-V2 writers skipped in reconcile loop |

See also `docs/operations/SCHEDULER_AND_WORKERS.md` (Site Sync section).

## 11. Transactions and side effects

- Catalog writes happen inside reconciler/inbound processors (articles, URL catalog, provider KW/scores, profile suggestions).
- Manual overrides and manual link rows are preserved; provider/workspace layers stored separately.
- Cancel does **not** undo successful reconciles.
- Outbound article publish / media push is **not** a Site Sync side effect (see WordPress Bridge).
- Heartbeat: `SiteSyncHeartbeatService` on queue/scheduler touches.

## 12. Retry and recovery

| Situation | Action |
|-----------|--------|
| Failed step | `site.retry_sync_step` / Resume |
| Stuck run | Resume or Cancel from Ops Center |
| Dead-letter inbound | `site.requeue_inbound_event` |
| Drift | `seo:site-sync-reconcile {site_id} --mode=standard` |
| Step job tries | `ProcessSiteSyncStepJob` `$tries = 3` |
| Inbound job tries | `$tries = 5` |
| WP outbox max attempts | `dead_letter` + retention cleanup; `retry_dead_letter()` |
| Emergency | Config `emergency_rollback` / env equivalent — forces legacy path |

## 13. Compatibility paths

- **Legacy push-content**: still accepted; V2 writers skip links/keywords/scores dual-apply (`SiteSyncCompatPushOwnershipContractTest`).
- **snapshot-callback**: compat alongside `delta-event`.
- **Legacy Domain Sync buttons**: behind feature flag / emergency rollback only — one primary Sync button is default.
- **Min bridge**: `1.0.64` for `site_sync.v1`; newer plugin versions add taxonomy/media/etc. without changing schema id.
- **Bootstrap / backfill (ops summary only):**
  - Bootstrap: capability + lightweight manifest → preview batches → confirm → queued snapshot → stamp `seo_site_sync_v2_bootstrapped_at`.
  - Backfill: CLI dry-run/execute; modes `profile|links|keywords|scores|articles|all`; never delete legacy; Manual preserved; unknown scores → `legacy_unknown`; no full-site HTML parse; no AI.
- Historical cutover/shadow/playbook docs live under `docs/archive/site-sync/` — not live manuals.

## 14. Forbidden paths

- Treat Domain **Save** as Sync (must not dispatch keyword/HTML/Site Sync jobs from save).
- Dual-apply links/keywords/scores via `push-content` on V2 writer sites.
- Invent provider (Rank Math/Yoast/…) when capability unknown — use `legacy_unknown` / workspace fallback.
- Full-site HTML scrape as Sync.
- Agent `site.sync` activating cutover.
- Outbound Laravel → WP trash/delete as part of Site Sync.
- Log API tokens, HMAC secrets, or full article bodies on security failure.
- Use archive CUTOVER/BOOTSTRAP/PLAYBOOK docs as runtime SoT.
- Point GSC sync (`GscSyncOperationService`) at Site Sync — different module.

## 15. Tests and invariants

| Invariant | Test / evidence |
|-----------|-----------------|
| Schema `site_sync.v1`, min bridge `1.0.64`, 9 steps order | `SiteSyncV2ArchitectureFreezeTest` |
| Keyword priority Manual > Provider > Workspace | same |
| Domain save ≠ `DomainLinkListKeywordSyncService` | same |
| V2 writer skips push enrich | `SiteSyncCompatPushOwnershipContractTest` |
| Unique job ids | `ArchitectureHardeningLockContractTest` |
| Ops Center Site Sync tab; hidden SiteSync nav | `ContentProjectOperationsCenterTest` |
| Wave / force-full / score / cutover freezes | `SiteSyncV2Wave*FreezeTest`, `SiteSyncV2ForceFullFreezeTest`, `SiteSyncV2ScorePipelineFreezeTest` |
| Agent CLI site-sync mapping | `AgentMcpSiteCliFixTest` |

Manual:

```text
$PHP_BIN vendor/bin/phpunit --filter=SiteSyncV2ArchitectureFreezeTest
$PHP_BIN vendor/bin/phpunit --filter=SiteSyncCompatPushOwnershipContractTest
$PHP_BIN vendor/bin/phpunit --filter=ContentProjectOperationsCenterTest
```

Pointers also in `docs/operations/TESTING.md`.

## 16. Related documents

- [WORDPRESS_BRIDGE.md](WORDPRESS_BRIDGE.md) — plugin REST, publish push, media, tokens
- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) — publish/approve (not Site Sync)
- [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md) — `/site-sync` CLI + Gateway
- [../operations/SCHEDULER_AND_WORKERS.md](../operations/SCHEDULER_AND_WORKERS.md) — queues/cron
- [../operations/TESTING.md](../operations/TESTING.md) — Site Sync filters
- [../architecture/ARCHITECTURE_FREEZE_V1.md](../architecture/ARCHITECTURE_FREEZE_V1.md) — platform freeze
- GSC (separate): [SEO_AUDIT_AND_KEYWORDS.md](SEO_AUDIT_AND_KEYWORDS.md) — not Site Sync
- Historical: `docs/archive/site-sync/`
)
