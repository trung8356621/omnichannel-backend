> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../operations/DEPLOYMENT.md
> Purpose: implementation history only
# Content Project PHP Engine â€” Production Checklist (Phase 1.5)

KhÃ´ng báº­t `CONTENT_PROJECT_PHP_ENGINE=true` toÃ n há»‡ thá»‘ng ngay.  
Æ¯u tiÃªn: **per-run checkbox** hoáº·c `CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS`.

## 1. Deploy

- [ ] Deploy code Phase 1.5 (engine + job + JS guards + status command)
- [ ] `npm run build` náº¿u `project-run-queue.js` chÆ°a lÃªn production
- [ ] KhÃ´ng cáº§n migration additive cho engine settings (JSON `seo_project_runs.settings`)
- [ ] DÃ¹ng **Ä‘Ãºng PHP binary** queue/cron Ä‘ang cháº¡y (khÃ´ng Ä‘oÃ¡n `/usr/bin/php`)

```text
{PHP_BIN} artisan config:clear
{PHP_BIN} artisan optimize:clear
{PHP_BIN} artisan queue:restart
```

- [ ] Worker listen queue `seo-content-run` (timeout â‰¥ 900)
- [ ] PHP-FPM/OPcache reload náº¿u `validate_timestamps=0`

## 2. Feature flag / rollout an toÃ n

| CÃ¡ch | Env / UI | Pháº¡m vi |
|---|---|---|
| Global OFF (máº·c Ä‘á»‹nh) | `CONTENT_PROJECT_PHP_ENGINE=false` | Legacy JS |
| Project allowlist | `CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS=12,34` | Chá»‰ project Ä‘Ã³ |
| Per-run | Checkbox Â«PHP EngineÂ» khi Start | Chá»‰ run Ä‘Ã³ |
| Global ON | `CONTENT_PROJECT_PHP_ENGINE=true` | Táº¥t cáº£ (chá»‰ sau A/B á»•n) |

Resolution: `run.settings.use_php_engine` â†’ stamp `php_engine.orchestration=php` â†’ project allowlist â†’ global.

## 3. Health / status (read-only)

```text
{PHP_BIN} artisan seo:content-project-run:status {runId}
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

Kiá»ƒm tra output:

- [ ] `feature_flag.for_run`
- [ ] `counts` pending/running/completed/failed/cancelled
- [ ] `dispatch` / `heartbeat.age_seconds` / `current_step`
- [ ] `health.ok` / `health.warnings` / `health.errors`
- [ ] KhÃ´ng cÃ³ 2 processing article cÃ¹ng run

## 4. Trial run (3â€“5 article)

- [ ] Start **má»™t láº§n** vá»›i PHP Engine checkbox
- [ ] Request web tráº£ nhanh
- [ ] ÄÃºng 1 article running
- [ ] F5 / Ä‘Ã³ng tab â†’ backend tiáº¿p tá»¥c
- [ ] Article 1 completed â†’ má»Ÿ editor; article 2 váº«n cháº¡y
- [ ] Fail cÃ³ chá»§ Ä‘Ã­ch â†’ article sau váº«n cháº¡y
- [ ] Stop khi Ä‘ang cháº¡y â†’ `stopping` â†’ khÃ´ng dispatch má»›i â†’ `cancelled`
- [ ] Network khÃ´ng JS `runItemQueued` loop
- [ ] So sÃ¡nh song song 1 run legacy (checkbox OFF) trÃªn cÃ¹ng project náº¿u cáº§n

## 5. TTL / heartbeat config

```env
CONTENT_PROJECT_ACTIVE_DISPATCH_TTL_MINUTES=45
CONTENT_PROJECT_HEARTBEAT_STALE_MINUTES=20
```

- Heartbeat stale â†’ **warning only** (status/health), khÃ´ng auto-resume
- Release stale dispatch chá»‰ khi TTL háº¿t **vÃ ** heartbeat cháº¿t
- Heartbeat job: claim / pre-run / post-run (khÃ´ng mid-LLM â€” limitation)

## 6. Worker crash / OOM / reboot

- [ ] Sau crash: `status` hiá»‡n `stale_dispatch_releasable` hoáº·c heartbeat_stale
- [ ] Gá»i `resume` thá»§ cÃ´ng (ops) hoáº·c Start idempotent / stop â†’ sweep TTL release
- [ ] KhÃ´ng Ä‘á»ƒ `active_dispatch` káº¹t mÃ£i sau TTL+dead heartbeat

## 7. Manual stop

- [ ] Stop â†’ `stopping` (khÃ´ng `completed`)
- [ ] Finalize-once (`finalized_at`) â€” gá»i láº¡i no-op
- [ ] Pending abandon chá»‰ khi khÃ´ng cÃ²n processing/blocking dispatch

## 8. Rollback

```env
CONTENT_PROJECT_PHP_ENGINE=false
# bá» project ids allowlist náº¿u cÃ³
```

```text
{PHP_BIN} artisan config:clear
{PHP_BIN} artisan queue:restart
```

- [ ] Run má»›i dÃ¹ng legacy JS
- [ ] Run Ä‘Ã£ stamp `orchestration=php` váº«n PHP path cho run Ä‘Ã³ (cá»‘ Ã½ A/B) â€” Ä‘á»£i terminal; khÃ´ng mix JS vÃ o run Ä‘ang engine
- [ ] History giá»¯ nguyÃªn

## 9. Recovery playbook

```text
# Dry-run (máº·c Ä‘á»‹nh â€” khÃ´ng ghi DB)
{PHP_BIN} artisan seo:content-project-run:recover {runId}

# Apply chá»‰ khi plan.eligible_for_stale_release=true
{PHP_BIN} artisan seo:content-project-run:recover {runId} --apply --token=<token_tá»«_plan>
```

Gates `--apply`: TTL háº¿t + heartbeat cháº¿t + processing=0 + run chÆ°a terminal + token khá»›p.

| Triá»‡u chá»©ng | Viá»‡c lÃ m |
|---|---|
| `stopping_mismatch_should_finalize` | Stop láº¡i / `resume` náº¿u still allowsDispatch / recover rá»“i finalize |
| `duplicated_active_article` | Stop run; inspect items; khÃ´ng Start láº¡i vá»™i |
| `orphan_processing_row` | Inspect item; khÃ´ng auto-reset pending |
| `heartbeat_stale_but_processing_active` | **Warning only** â€” khÃ´ng release; Ä‘á»£i article xong |
| `stale_dispatch_releasable` | `recover --apply --token=...` |
| Worker OOM mid-article | Äá»£i TTL+dead heartbeat+processing=0 rá»“i recover |

## 10. ÄÃ¡nh giÃ¡ trÆ°á»›c Phase 2 (SSE)

- [ ] A/B á»•n trÃªn â‰¥1 project
- [ ] KhÃ´ng duplicate dispatch trong trial
- [ ] Stop/finalize Ä‘Ãºng
- [ ] Ops quen `status` + `recover`
- [ ] **ChÆ°a** báº­t SSE / EventSource

Verdict template: giá»¯ `Ready with limitations` / `Canary Ready` cho Ä‘áº¿n khi Phase 1.6 evidence Ä‘á»§.

---

## 11. Phase 1.6 â€” Production canary (báº¯t buá»™c evidence)

### KhÃ´ng lÃ m

- KhÃ´ng `CONTENT_PROJECT_PHP_ENGINE=true` global
- KhÃ´ng nhá»“i `CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS` hÃ ng loáº¡t
- KhÃ´ng SSE / mid-provider heartbeat / PromptRunner

### Pre-flight (Ä‘Ãºng PHP binary queue)

```text
which php
php -v
readlink -f $(which php) || true
supervisorctl status
ps aux | grep -E "artisan (queue:work|horizon)" | grep -v grep

# Láº¥y PHP_BIN tá»« command Supervisor (vÃ­ dá»¥):
# /www/server/php/83/bin/php /www/wwwroot/seo.teamviahe.com/artisan queue:work --queue=seo-content-run,...

{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan about
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan config:clear
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan queue:restart
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan tinker --execute="print_r(config('seo-content-ai.content_project'));"
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

Pháº£i tháº¥y: `php_engine=false`, worker listen `seo-content-run`, class engine/job tá»“n táº¡i.

### Canary setup

1. Project khÃ´ng quan trá»ng; 3 article prompt Ä‘Ã£ á»•n.
2. Start vá»›i checkbox **PHP Engine** ON (chá»‰ 1 run).
3. Ngay sau create/start:  
   `{PHP_BIN} artisan seo:content-project-run:status {runId}`  
   Ká»³ vá»ng: `feature_flag.for_run=true`, `orchestration=php`, `php_engine.enabled=true`, khÃ´ng processing trÆ°á»›c dispatch, health khÃ´ng error blocking.

### Evidence log (Ä‘iá»n tay)

| Field | Value |
|---|---|
| PHP_BIN | |
| Worker PID / command | |
| Canary happy run ID | |
| Canary fail-continue run ID | |
| Canary stop run ID | |
| Legacy isolation run ID | |
| Start time | |
| Test output (tests/assertions) | |

### Flows

1. **Happy:** Start 1Ã— â†’ F5 â†’ Ä‘Ã³ng tab â†’ article1 done + editor â†’ article2/3 â†’ finalize khÃ´ng JS complete  
2. **Fail continue:** 1 fail giá»¯a â†’ article sau cháº¡y â†’ completed + failed count  
3. **Stop:** stop khi article1 running â†’ stopping â†’ cancelled; khÃ´ng article2  
4. **Edit parallel:** sá»­a article1 khi article2 cháº¡y â†’ khÃ´ng bá»‹ ghi Ä‘Ã¨  
5. **Legacy isolation:** run checkbox OFF khÃ´ng bá»‹ PHP claim  

### Verdict sau canary

- Thiáº¿u evidence / fail blocking â†’ `Not Ready` hoáº·c giá»¯ `Ready with limitations`
- Äá»§ 5 proof â†’ `Canary Ready`
- Chá»‰ sau nhiá»u canary + ops quen â†’ cÃ¢n nháº¯c `Production Ready with limitations`
- **KhÃ´ng** `Production Ready` / **Default-on candidate** chá»‰ tá»« 1 happy path hoáº·c source tests

---

## 12. Phase 1.8 â€” Orchestration stamp (ops)

- Badge UI Ä‘á»c stamp run, khÃ´ng Ä‘á»c global.
- Global Ä‘á»•i giá»¯a run **khÃ´ng** Ä‘á»•i ownership.
- Náº¿u status hiá»‡n `orchestration` lá»‡ch ká»³ vá»ng: khÃ´ng sá»­a tay JSON khi run Ä‘ang processing â€” Stop/terminal trÆ°á»›c.
- Legacy action bá»‹ block â†’ log `content_project_run.legacy_action_blocked` (khÃ´ng spam poll).
