> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Run Engine â€” Phase 1 Handoff

NgÃ y: 2026-07-25  
Doc gá»‘c: `docs/architecture/CONTENT_PROJECT_RUN_ENGINE_REFACTOR.md`

## Verdict

**Phase 1.5:** Ready with limitations  
**Phase 1.6:** Canary **chÆ°a cháº¡y trÃªn production bá»Ÿi agent** â€” chá» operator evidence.  
Verdict hiá»‡n táº¡i váº«n: **Ready with limitations** (khÃ´ng nÃ¢ng Canary Ready / Production Ready trÆ°á»›c khi dÃ¡n snapshot tháº­t).

KhÃ´ng báº­t global. Canary = checkbox PHP Engine trÃªn **má»™t** run má»›i (3 article).

## Phase 1.6 â€” Canary + recovery (ops)

### Stamp khi checkbox ON

`settings.use_php_engine=true`  
`settings.php_engine.enabled=true`  
`settings.php_engine.orchestration=php`  
(start() stamp láº¡i `started_at`)

### Commands

```text
{PHP_BIN} artisan seo:content-project-run:status {runId}
{PHP_BIN} artisan seo:content-project-run:recover {runId}
{PHP_BIN} artisan seo:content-project-run:recover {runId} --apply --token=...
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

### Heartbeat limitation (khÃ´ng coi lÃ  crash)

`heartbeat_stale_but_processing_active` = warning only; **khÃ´ng** release dispatch.

### Late-write protection (article edit song song)

- Item terminal â†’ job stale ignore / khÃ´ng retryTask láº¡i  
- Dispatch token mismatch â†’ discard  
- Cancel sau provider: discard non-success  
- KhÃ´ng article versioning lá»›n Phase 1

### Evidence template (operator paste)

```text
Canary run IDs:
PHP binary:
Queue worker command/PID:
Feature flag resolution:
Happy path:
Failure continue:
Stop:
Edit parallel:
Legacy isolation:
Status snapshots: (attach)
Health warnings/errors:
Log timeline:
PHPUnit output:
Bugs found / files patched:
Recover dry-run:
Rollback tried:
Verdict:
```

## Phase 1.7 â€” TÃ¡ch execution khá»i retryTask

### SÆ¡ Ä‘á»“ cÅ©

```
Job â†’ ArticleRunner â†’ retryTask() â†’ runOneTask() â†’ CreateArticlesFromTaskService
UI Retry â†’ retryTask() â†’ runOneTask()
```

### SÆ¡ Ä‘á»“ má»›i

```
Job â†’ ArticleRunner â†’ ContentProjectTaskExecutionService::execute
UI Retry â†’ retryTask (thin) â†’ ContentProjectTaskExecutionService::execute
Batch execute â†’ ContentProjectTaskExecutionService::executeLoadedTask
                â†“
         runTaskPipeline (claim/provider/persist â€” váº«n trong WorkflowRunService)
                â†“
         ContentProjectTaskExecutionResult
```

### File táº¡o

- `Support/RunEngine/ContentProjectTaskExecutionResult.php`
- `Services/RunEngine/ContentProjectTaskExecutionService.php`
- `tests/Unit/ContentProjectTaskExecutionServiceTest.php`

### File sá»­a

- `ContentProjectArticleRunner` â€” khÃ´ng gá»i `retryTask`
- `SeoProjectWorkflowRunService::retryTask` â€” adapter ~12 dÃ²ng
- `SeoProjectWorkflowRunService::runTaskPipeline` â€” public entry cho ExecutionService
- ServiceProvider bind ExecutionService

### Engine cÃ²n phá»¥ thuá»™c retryTask?

**KhÃ´ng.** Engine â†’ Job â†’ ArticleRunner â†’ ExecutionService.

### Known limitation 1.7

Pipeline body (`runOneTask`) chÆ°a move háº¿t sang ExecutionService â€” gá»i qua `runTaskPipeline`. Entry duy nháº¥t cho Runner/Retry Ä‘Ã£ lÃ  ExecutionService.

## Phase 1.8 â€” Stamp + legacy isolation

- Resolver duy nháº¥t: `orchestrationFor($run)` â€” stamp báº¥t biáº¿n.
- Historical fallback: active+dispatchâ†’php; else legacy; khÃ´ng global steal.
- Legacy mutate block + log `content_project_run.legacy_action_blocked`.
- Manual retry: block khi PHP active; OK khi terminal.
- UI badge `Engine: PHP|Legacy` tá»« stamp.
- Test: `ContentProjectOrchestrationIsolationTest`.

**Verdict Ä‘á» xuáº¥t:** Ready with limitations (giá»¯). Canary Ready / Default-on candidate chá»‰ sau evidence production.

## Root flow

### Legacy (flag OFF)

List Start â†’ view-run `?autorun=1` â†’ JS `processQueue`/`startQueue` â†’ Livewire `runItemQueued` â†’ `retryTask` â†’ JS next â†’ JS `completeRunQueue`.

### Phase 1 (flag ON)

List Start â†’ `ContentProjectRunEngine::start` (idempotent) â†’ `dispatchNextArticle` (1 job) â†’ view-run **khÃ´ng** autorun â†’ JS chá»‰ poll â†’ `RunContentProjectArticleJob` â†’ `ContentProjectArticleRunner` (`retryTask`, `markCompleted:false`) â†’ `handleArticleFinished` â†’ next hoáº·c `finalizeIfDone`.

Stop: `forceStopRunQueue` â†’ `requestStop` â†’ `runningâ†’stoppingâ†’cancelled`.

## Files

### Táº¡o

- `Services/RunEngine/ContentProjectRunEngine.php`
- `Services/RunEngine/ContentProjectArticleRunner.php`
- `Services/RunEngine/RunCancellationGuard.php`
- `Services/RunEngine/ContentProjectRunEventPublisher.php`
- `Services/RunEngine/LoggingContentProjectRunEventPublisher.php`
- `Jobs/RunContentProjectArticleJob.php`
- `Support/RunEngine/ArticleExecutionResult.php` (result object; doc aka ContentProjectArticleRunResult)
- `Support/RunEngine/ContentProjectRunEngineFeature.php`
- `Support/RunEngine/ContentProjectRunStatusMapper.php`
- `Enums/ContentProjectRunSemanticStatus.php`
- `Enums/ContentProjectArticleSemanticStatus.php`
- `Console/ContentProjectRunStatusCommand.php`
- `Console/ContentProjectRunRecoverCommand.php`
- `tests/Unit/ContentProjectRunEnginePhase1Test.php`

### Sá»­a

- `config/seo-content-ai.php` â€” `php_engine`, `run_queue`, stale minutes
- `Models/SeoProjectRun.php` â€” `STATUS_STOPPING` / `STATUS_CANCELLED`
- `SeoContentAiServiceProvider.php` â€” binds + command
- `ListSeoProjectRuns.php` â€” engine start khi flag ON
- `ViewSeoProjectRun.php` â€” reject legacy execute; stopâ†’requestStop; poll read-only
- `resources/js/project-run-queue.js` â€” disable orchestration khi `phpEngine`

## State transitions

```
pending/seeded â†’ running (start)
running â†’ stopping (requestStop)
stopping â†’ cancelled (khÃ´ng cÃ²n processing + khÃ´ng blocking active_dispatch)
running â†’ completed (háº¿t article-level pending/processing; cÃ³ thá»ƒ failed>0)
terminal completed|cancelled|failed â†’ start no-op (khÃ´ng reset)
```

Article:

```
pending â†’ (job) processing â†’ success|failed|cancelled-as-failed+message
failed domain â†’ váº«n dispatch article káº¿
cancelled â†’ khÃ´ng dispatch káº¿
```

## Claim / lock strategy

- Run row `lockForUpdate` + next pending item `lockForUpdate`
- Reservation: `settings.php_engine.active_dispatch` + token
- DB claim tháº­t: `SeoProjectRunItemService::claimForExecution` trong `retryTask`
- Unique job: `content-project-run-article:{run}:{runItem}:{attempt}`
- Stale sweep: terminal / missing / age â‰¥ `SEO_CONTENT_PROJECT_RUN_ITEM_STALE_MINUTES` (default 30)

## Exception policy

- Domain fail â†’ article failed â†’ next
- Cancel â†’ no next â†’ finalize cancelled
- `Job::failed` â†’ mark failed náº¿u cÃ²n runnable â†’ next (Phase 1 treat as domain terminal for chain)
- KhÃ´ng finally-dispatch vÃ´ Ä‘iá»u kiá»‡n

## Cancellation

- DB-first `stopping`
- Safe boundaries: pre-job, pre-runner, runner start, post-provider
- Provider muá»™n: success Ä‘Ã£ persist giá»¯; non-success discard
- KhÃ´ng clear toÃ n há»‡ thá»‘ng queue; chá»‰ run + cancel active steps cá»§a run

## JS paths táº¯t (flag ON)

- `processQueue`, `startQueue`, `runSingleTask`, `handleStartQueue`, autorun
- Livewire: `runItemQueued` reject; `beginRunQueue`/`completeRunQueue` no-op
- CÃ²n: Stop â†’ `requestStop`; poll â†’ `pollRunProgress`

## Ops command

```bash
php artisan seo:content-project-run:status {runId} [--site=]
```

Read-only JSON: status, flag, counts, active_dispatch, stop, next candidate, last_transition.

## Tests

```bash
php vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

KhÃ´ng dÃ¹ng `php artisan test`.  
Agent khÃ´ng cháº¡y local (remote-first). Output ká»³ vá»ng: all green contract/unit.

## Manual production (báº¯t buá»™c trÆ°á»›c scale)

1. Giá»¯ `CONTENT_PROJECT_PHP_ENGINE=false`
2. Canary báº±ng checkbox PHP Engine (Phase 1.6) â€” xem checklist Â§11
3. KhÃ´ng báº­t global / project allowlist hÃ ng loáº¡t trÆ°á»›c Canary Ready

## Deploy (Ä‘iá»n binary tháº­t trÃªn server)

```text
# KHÃ”NG Ä‘á»•i /usr/bin/php trong patch nÃ y.
# DÃ¹ng binary Supervisor/cron Ä‘ang cháº¡y queue:

{PHP_BIN} artisan config:clear
{PHP_BIN} artisan optimize:clear
{PHP_BIN} artisan queue:restart
# migration: khÃ´ng cÃ³ additive migration Phase 1 engine
# Vite: npm run build náº¿u JS chÆ°a deploy
# PHP-FPM/OPcache reload náº¿u opcache validate_timestamps=0

{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

## Rollback

1. `CONTENT_PROJECT_PHP_ENGINE=false`
2. Config clear + queue restart
3. Legacy JS path láº¡i
4. History giá»¯; `stopping`/`cancelled` váº«n Ä‘á»c Ä‘Æ°á»£c

## Gaps cÃ²n láº¡i (khÃ´ng block trial nhá»)

- ChÆ°a DB integration test vá»›i Bus::fake trÃªn CI SEO connection
- Reservation chÆ°a flip item status lÃºc dispatch (cá»‘ Ã½)
- SSE / API / Agent public = Phase 2+
