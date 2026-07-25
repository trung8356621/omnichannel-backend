# Content Project PHP Engine — Production Checklist (Phase 1.5)

Không bật `CONTENT_PROJECT_PHP_ENGINE=true` toàn hệ thống ngay.  
Ưu tiên: **per-run checkbox** hoặc `CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS`.

## 1. Deploy

- [ ] Deploy code Phase 1.5 (engine + job + JS guards + status command)
- [ ] `npm run build` nếu `project-run-queue.js` chưa lên production
- [ ] Không cần migration additive cho engine settings (JSON `seo_project_runs.settings`)
- [ ] Dùng **đúng PHP binary** queue/cron đang chạy (không đoán `/usr/bin/php`)

```text
{PHP_BIN} artisan config:clear
{PHP_BIN} artisan optimize:clear
{PHP_BIN} artisan queue:restart
```

- [ ] Worker listen queue `seo-content-run` (timeout ≥ 900)
- [ ] PHP-FPM/OPcache reload nếu `validate_timestamps=0`

## 2. Feature flag / rollout an toàn

| Cách | Env / UI | Phạm vi |
|---|---|---|
| Global OFF (mặc định) | `CONTENT_PROJECT_PHP_ENGINE=false` | Legacy JS |
| Project allowlist | `CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS=12,34` | Chỉ project đó |
| Per-run | Checkbox «PHP Engine» khi Start | Chỉ run đó |
| Global ON | `CONTENT_PROJECT_PHP_ENGINE=true` | Tất cả (chỉ sau A/B ổn) |

Resolution: `run.settings.use_php_engine` → stamp `php_engine.orchestration=php` → project allowlist → global.

## 3. Health / status (read-only)

```text
{PHP_BIN} artisan seo:content-project-run:status {runId}
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

Kiểm tra output:

- [ ] `feature_flag.for_run`
- [ ] `counts` pending/running/completed/failed/cancelled
- [ ] `dispatch` / `heartbeat.age_seconds` / `current_step`
- [ ] `health.ok` / `health.warnings` / `health.errors`
- [ ] Không có 2 processing article cùng run

## 4. Trial run (3–5 article)

- [ ] Start **một lần** với PHP Engine checkbox
- [ ] Request web trả nhanh
- [ ] Đúng 1 article running
- [ ] F5 / đóng tab → backend tiếp tục
- [ ] Article 1 completed → mở editor; article 2 vẫn chạy
- [ ] Fail có chủ đích → article sau vẫn chạy
- [ ] Stop khi đang chạy → `stopping` → không dispatch mới → `cancelled`
- [ ] Network không JS `runItemQueued` loop
- [ ] So sánh song song 1 run legacy (checkbox OFF) trên cùng project nếu cần

## 5. TTL / heartbeat config

```env
CONTENT_PROJECT_ACTIVE_DISPATCH_TTL_MINUTES=45
CONTENT_PROJECT_HEARTBEAT_STALE_MINUTES=20
```

- Heartbeat stale → **warning only** (status/health), không auto-resume
- Release stale dispatch chỉ khi TTL hết **và** heartbeat chết
- Heartbeat job: claim / pre-run / post-run (không mid-LLM — limitation)

## 6. Worker crash / OOM / reboot

- [ ] Sau crash: `status` hiện `stale_dispatch_releasable` hoặc heartbeat_stale
- [ ] Gọi `resume` thủ công (ops) hoặc Start idempotent / stop → sweep TTL release
- [ ] Không để `active_dispatch` kẹt mãi sau TTL+dead heartbeat

## 7. Manual stop

- [ ] Stop → `stopping` (không `completed`)
- [ ] Finalize-once (`finalized_at`) — gọi lại no-op
- [ ] Pending abandon chỉ khi không còn processing/blocking dispatch

## 8. Rollback

```env
CONTENT_PROJECT_PHP_ENGINE=false
# bỏ project ids allowlist nếu có
```

```text
{PHP_BIN} artisan config:clear
{PHP_BIN} artisan queue:restart
```

- [ ] Run mới dùng legacy JS
- [ ] Run đã stamp `orchestration=php` vẫn PHP path cho run đó (cố ý A/B) — đợi terminal; không mix JS vào run đang engine
- [ ] History giữ nguyên

## 9. Recovery playbook

```text
# Dry-run (mặc định — không ghi DB)
{PHP_BIN} artisan seo:content-project-run:recover {runId}

# Apply chỉ khi plan.eligible_for_stale_release=true
{PHP_BIN} artisan seo:content-project-run:recover {runId} --apply --token=<token_từ_plan>
```

Gates `--apply`: TTL hết + heartbeat chết + processing=0 + run chưa terminal + token khớp.

| Triệu chứng | Việc làm |
|---|---|
| `stopping_mismatch_should_finalize` | Stop lại / `resume` nếu still allowsDispatch / recover rồi finalize |
| `duplicated_active_article` | Stop run; inspect items; không Start lại vội |
| `orphan_processing_row` | Inspect item; không auto-reset pending |
| `heartbeat_stale_but_processing_active` | **Warning only** — không release; đợi article xong |
| `stale_dispatch_releasable` | `recover --apply --token=...` |
| Worker OOM mid-article | Đợi TTL+dead heartbeat+processing=0 rồi recover |

## 10. Đánh giá trước Phase 2 (SSE)

- [ ] A/B ổn trên ≥1 project
- [ ] Không duplicate dispatch trong trial
- [ ] Stop/finalize đúng
- [ ] Ops quen `status` + `recover`
- [ ] **Chưa** bật SSE / EventSource

Verdict template: giữ `Ready with limitations` / `Canary Ready` cho đến khi Phase 1.6 evidence đủ.

---

## 11. Phase 1.6 — Production canary (bắt buộc evidence)

### Không làm

- Không `CONTENT_PROJECT_PHP_ENGINE=true` global
- Không nhồi `CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS` hàng loạt
- Không SSE / mid-provider heartbeat / PromptRunner

### Pre-flight (đúng PHP binary queue)

```text
which php
php -v
readlink -f $(which php) || true
supervisorctl status
ps aux | grep -E "artisan (queue:work|horizon)" | grep -v grep

# Lấy PHP_BIN từ command Supervisor (ví dụ):
# /www/server/php/83/bin/php /www/wwwroot/seo.teamviahe.com/artisan queue:work --queue=seo-content-run,...

{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan about
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan config:clear
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan queue:restart
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/artisan tinker --execute="print_r(config('seo-content-ai.content_project'));"
{PHP_BIN} /www/wwwroot/seo.teamviahe.com/vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
```

Phải thấy: `php_engine=false`, worker listen `seo-content-run`, class engine/job tồn tại.

### Canary setup

1. Project không quan trọng; 3 article prompt đã ổn.
2. Start với checkbox **PHP Engine** ON (chỉ 1 run).
3. Ngay sau create/start:  
   `{PHP_BIN} artisan seo:content-project-run:status {runId}`  
   Kỳ vọng: `feature_flag.for_run=true`, `orchestration=php`, `php_engine.enabled=true`, không processing trước dispatch, health không error blocking.

### Evidence log (điền tay)

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

1. **Happy:** Start 1× → F5 → đóng tab → article1 done + editor → article2/3 → finalize không JS complete  
2. **Fail continue:** 1 fail giữa → article sau chạy → completed + failed count  
3. **Stop:** stop khi article1 running → stopping → cancelled; không article2  
4. **Edit parallel:** sửa article1 khi article2 chạy → không bị ghi đè  
5. **Legacy isolation:** run checkbox OFF không bị PHP claim  

### Verdict sau canary

- Thiếu evidence / fail blocking → `Not Ready` hoặc giữ `Ready with limitations`
- Đủ 5 proof → `Canary Ready`
- Chỉ sau nhiều canary + ops quen → cân nhắc `Production Ready with limitations`
- **Không** `Production Ready` / **Default-on candidate** chỉ từ 1 happy path hoặc source tests

---

## 12. Phase 1.8 — Orchestration stamp (ops)

- Badge UI đọc stamp run, không đọc global.
- Global đổi giữa run **không** đổi ownership.
- Nếu status hiện `orchestration` lệch kỳ vọng: không sửa tay JSON khi run đang processing — Stop/terminal trước.
- Legacy action bị block → log `content_project_run.legacy_action_blocked` (không spam poll).
