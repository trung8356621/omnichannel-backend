# Content Project Run Engine — Phase 1 Handoff

Ngày: 2026-07-25  
Doc gốc: `docs/architecture/CONTENT_PROJECT_RUN_ENGINE_REFACTOR.md`

## Verdict

**Phase 1.5:** Ready with limitations  
**Phase 1.6:** Canary **chưa chạy trên production bởi agent** — chờ operator evidence.  
Verdict hiện tại vẫn: **Ready with limitations** (không nâng Canary Ready / Production Ready trước khi dán snapshot thật).

Không bật global. Canary = checkbox PHP Engine trên **một** run mới (3 article).

## Phase 1.6 — Canary + recovery (ops)

### Stamp khi checkbox ON

`settings.use_php_engine=true`  
`settings.php_engine.enabled=true`  
`settings.php_engine.orchestration=php`  
(start() stamp lại `started_at`)

### Commands

```text
{PHP_BIN} artisan seo:content-project-run:status {runId}
{PHP_BIN} artisan seo:content-project-run:recover {runId}
{PHP_BIN} artisan seo:content-project-run:recover {runId} --apply --token=...
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

### Heartbeat limitation (không coi là crash)

`heartbeat_stale_but_processing_active` = warning only; **không** release dispatch.

### Late-write protection (article edit song song)

- Item terminal → job stale ignore / không retryTask lại  
- Dispatch token mismatch → discard  
- Cancel sau provider: discard non-success  
- Không article versioning lớn Phase 1

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

## Phase 1.7 — Tách execution khỏi retryTask

### Sơ đồ cũ

```
Job → ArticleRunner → retryTask() → runOneTask() → CreateArticlesFromTaskService
UI Retry → retryTask() → runOneTask()
```

### Sơ đồ mới

```
Job → ArticleRunner → ContentProjectTaskExecutionService::execute
UI Retry → retryTask (thin) → ContentProjectTaskExecutionService::execute
Batch execute → ContentProjectTaskExecutionService::executeLoadedTask
                ↓
         runTaskPipeline (claim/provider/persist — vẫn trong WorkflowRunService)
                ↓
         ContentProjectTaskExecutionResult
```

### File tạo

- `Support/RunEngine/ContentProjectTaskExecutionResult.php`
- `Services/RunEngine/ContentProjectTaskExecutionService.php`
- `tests/Unit/ContentProjectTaskExecutionServiceTest.php`

### File sửa

- `ContentProjectArticleRunner` — không gọi `retryTask`
- `SeoProjectWorkflowRunService::retryTask` — adapter ~12 dòng
- `SeoProjectWorkflowRunService::runTaskPipeline` — public entry cho ExecutionService
- ServiceProvider bind ExecutionService

### Engine còn phụ thuộc retryTask?

**Không.** Engine → Job → ArticleRunner → ExecutionService.

### Known limitation 1.7

Pipeline body (`runOneTask`) chưa move hết sang ExecutionService — gọi qua `runTaskPipeline`. Entry duy nhất cho Runner/Retry đã là ExecutionService.

## Phase 1.8 — Stamp + legacy isolation

- Resolver duy nhất: `orchestrationFor($run)` — stamp bất biến.
- Historical fallback: active+dispatch→php; else legacy; không global steal.
- Legacy mutate block + log `content_project_run.legacy_action_blocked`.
- Manual retry: block khi PHP active; OK khi terminal.
- UI badge `Engine: PHP|Legacy` từ stamp.
- Test: `ContentProjectOrchestrationIsolationTest`.

**Verdict đề xuất:** Ready with limitations (giữ). Canary Ready / Default-on candidate chỉ sau evidence production.

## Root flow

### Legacy (flag OFF)

List Start → view-run `?autorun=1` → JS `processQueue`/`startQueue` → Livewire `runItemQueued` → `retryTask` → JS next → JS `completeRunQueue`.

### Phase 1 (flag ON)

List Start → `ContentProjectRunEngine::start` (idempotent) → `dispatchNextArticle` (1 job) → view-run **không** autorun → JS chỉ poll → `RunContentProjectArticleJob` → `ContentProjectArticleRunner` (`retryTask`, `markCompleted:false`) → `handleArticleFinished` → next hoặc `finalizeIfDone`.

Stop: `forceStopRunQueue` → `requestStop` → `running→stopping→cancelled`.

## Files

### Tạo

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

### Sửa

- `config/seo-content-ai.php` — `php_engine`, `run_queue`, stale minutes
- `Models/SeoProjectRun.php` — `STATUS_STOPPING` / `STATUS_CANCELLED`
- `SeoContentAiServiceProvider.php` — binds + command
- `ListSeoProjectRuns.php` — engine start khi flag ON
- `ViewSeoProjectRun.php` — reject legacy execute; stop→requestStop; poll read-only
- `resources/js/project-run-queue.js` — disable orchestration khi `phpEngine`

## State transitions

```
pending/seeded → running (start)
running → stopping (requestStop)
stopping → cancelled (không còn processing + không blocking active_dispatch)
running → completed (hết article-level pending/processing; có thể failed>0)
terminal completed|cancelled|failed → start no-op (không reset)
```

Article:

```
pending → (job) processing → success|failed|cancelled-as-failed+message
failed domain → vẫn dispatch article kế
cancelled → không dispatch kế
```

## Claim / lock strategy

- Run row `lockForUpdate` + next pending item `lockForUpdate`
- Reservation: `settings.php_engine.active_dispatch` + token
- DB claim thật: `SeoProjectRunItemService::claimForExecution` trong `retryTask`
- Unique job: `content-project-run-article:{run}:{runItem}:{attempt}`
- Stale sweep: terminal / missing / age ≥ `SEO_CONTENT_PROJECT_RUN_ITEM_STALE_MINUTES` (default 30)

## Exception policy

- Domain fail → article failed → next
- Cancel → no next → finalize cancelled
- `Job::failed` → mark failed nếu còn runnable → next (Phase 1 treat as domain terminal for chain)
- Không finally-dispatch vô điều kiện

## Cancellation

- DB-first `stopping`
- Safe boundaries: pre-job, pre-runner, runner start, post-provider
- Provider muộn: success đã persist giữ; non-success discard
- Không clear toàn hệ thống queue; chỉ run + cancel active steps của run

## JS paths tắt (flag ON)

- `processQueue`, `startQueue`, `runSingleTask`, `handleStartQueue`, autorun
- Livewire: `runItemQueued` reject; `beginRunQueue`/`completeRunQueue` no-op
- Còn: Stop → `requestStop`; poll → `pollRunProgress`

## Ops command

```bash
php artisan seo:content-project-run:status {runId} [--site=]
```

Read-only JSON: status, flag, counts, active_dispatch, stop, next candidate, last_transition.

## Tests

```bash
php vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

Không dùng `php artisan test`.  
Agent không chạy local (remote-first). Output kỳ vọng: all green contract/unit.

## Manual production (bắt buộc trước scale)

1. Giữ `CONTENT_PROJECT_PHP_ENGINE=false`
2. Canary bằng checkbox PHP Engine (Phase 1.6) — xem checklist §11
3. Không bật global / project allowlist hàng loạt trước Canary Ready

## Deploy (điền binary thật trên server)

```text
# KHÔNG đổi /usr/bin/php trong patch này.
# Dùng binary Supervisor/cron đang chạy queue:

{PHP_BIN} artisan config:clear
{PHP_BIN} artisan optimize:clear
{PHP_BIN} artisan queue:restart
# migration: không có additive migration Phase 1 engine
# Vite: npm run build nếu JS chưa deploy
# PHP-FPM/OPcache reload nếu opcache validate_timestamps=0

{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

## Rollback

1. `CONTENT_PROJECT_PHP_ENGINE=false`
2. Config clear + queue restart
3. Legacy JS path lại
4. History giữ; `stopping`/`cancelled` vẫn đọc được

## Gaps còn lại (không block trial nhỏ)

- Chưa DB integration test với Bus::fake trên CI SEO connection
- Reservation chưa flip item status lúc dispatch (cố ý)
- SSE / API / Agent public = Phase 2+
