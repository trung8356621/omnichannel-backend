# aaPanel Queue Runtime

> Status: Canonical  
> Owner: SeoContentAi (+ host ops)  
> Last verified: 2026-08-05  
> Host: `seo.teamviahe.com` (aaPanel cron, user root)  
> Related: [SCHEDULER_AND_WORKERS.md](SCHEDULER_AND_WORKERS.md), [DEPLOYMENT.md](DEPLOYMENT.md), [../audits/BACKEND_RUNTIME_PERFORMANCE_AUDIT.md](../audits/BACKEND_RUNTIME_PERFORMANCE_AUDIT.md)

Production queue model: **short-lived cron workers** + `flock`, not Supervisor/Horizon (unless host later proves otherwise).

**Do not** add `seo-content-run` to the shared Queue Worker. Generation uses a **dedicated** worker so long AI jobs do not block publish / Site Sync / automation / media.

---

## 1. Verified job contract (`RunContentProjectArticleJob`)

Source: `app/Addons/SeoContentAi/Jobs/RunContentProjectArticleJob.php`

| Property | Value | Notes |
|----------|-------|-------|
| Queue | `ContentProjectRunEngineFeature::queueName()` | Config `seo-content-ai.content_project.run_queue` ← env `CONTENT_PROJECT_RUN_QUEUE`, default **`seo-content-run`** |
| `$timeout` | **900** | Seconds; overrides worker `--timeout` when set on job |
| `$tries` | **1** | Overrides CLI `--tries` |
| `$backoff` | *(none)* | Not declared on job |
| `$uniqueFor` | **900** | `ShouldBeUnique` |
| `uniqueId()` | `content-project-run-article:{runId}:{runItemId}:{attempt}` | |

**Dispatch sites (production path):**

| Path | Queue |
|------|-------|
| Ctor `onQueue(ContentProjectRunEngineFeature::queueName())` | always |
| `ContentProjectRunEngine::dispatchNextArticle` → `RunContentProjectArticleJob::dispatch(...)->onQueue(queueName())` | async |
| Same method → `dispatchSync(...)` when `rerun_sync` | sync (no queue) |

**Invariant:**

```text
database queue retry_after  >  longest effective generation job timeout
1200  >  900
```

Repo defaults: `config/queue.php` database `retry_after` fallback **1200**; `.env.example` sets `DB_QUEUE_RETRY_AFTER=1200`.

---

## 2. Three aaPanel tasks (production)

Cadence: every minute. PHP binary: `/usr/local/lsws/lsphp83/bin/php`. Docroot: `/www/wwwroot/seo.teamviahe.com`.

### 2.1 Queue workers (split — do not mix WP publish with SEO audit)

Production must **not** put `automation-external` on the same process that drains `seo-audit`, `automation-policy`, or a large `default` backlog. Prefer separate flock locks per worker class.

#### automation-critical worker

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/bin/flock -n /tmp/seo-teamviahe-queue-critical.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--stop-when-empty \
--max-time=55 \
--timeout=180 \
--tries=3 \
--sleep=1 \
--queue=automation-critical \
>> storage/logs/queue-critical-cron.log 2>&1
```

#### automation-external worker (WordPress / external publish only)

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/bin/flock -n /tmp/seo-teamviahe-queue-external.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--stop-when-empty \
--max-time=55 \
--timeout=300 \
--tries=3 \
--sleep=1 \
--queue=automation-external \
>> storage/logs/queue-external-cron.log 2>&1
```

#### automation-policy worker

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/bin/flock -n /tmp/seo-teamviahe-queue-policy.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--stop-when-empty \
--max-time=55 \
--timeout=300 \
--tries=3 \
--sleep=1 \
--queue=automation-policy \
>> storage/logs/queue-policy-cron.log 2>&1
```

#### SEO maintenance worker (low concurrency)

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/bin/flock -n /tmp/seo-teamviahe-queue-seo-audit.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--stop-when-empty \
--max-time=55 \
--timeout=45 \
--tries=2 \
--sleep=1 \
--max-jobs=5 \
--queue=seo-audit \
>> storage/logs/queue-seo-audit-cron.log 2>&1
```

#### general worker

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/bin/flock -n /tmp/seo-teamviahe-queue.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--stop-when-empty \
--max-time=55 \
--timeout=300 \
--tries=3 \
--sleep=1 \
--queue=automation,seo,media_generation,default \
>> storage/logs/queue-cron.log 2>&1
```

General worker **must not** include `automation-external`, `automation-critical`, `automation-policy`, `seo-audit`, or `seo-content-run`.
Shared / general worker **must not** include `seo-content-run`.

### 2.2 Laravel Scheduler (existing)

Log: `storage/logs/cron-schedule.log`

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/local/lsws/lsphp83/bin/php artisan schedule:run \
>> storage/logs/cron-schedule.log 2>&1
```

### 2.3 Dedicated Content Project Queue Worker (production applied)

Task name: **Content Project Queue Worker**  
Task type: Shell Script · Every 1 minute · user root  
Lock: `/tmp/seo-teamviahe-content-run.lock` (separate from shared)  
Log: `storage/logs/content-run-queue-cron.log`

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/bin/flock -n /tmp/seo-teamviahe-content-run.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--queue=seo-content-run \
--stop-when-empty \
--max-jobs=1 \
--timeout=900 \
--tries=1 \
--sleep=1 \
>> storage/logs/content-run-queue-cron.log 2>&1
```

**Behavior:**

| Flag / lock | Meaning |
|-------------|---------|
| Separate flock | Does **not** block shared Queue Worker |
| `--queue=seo-content-run` only | Never consume shared queues |
| `--max-jobs=1` | Force PHP process recycle after each generation job |
| `--stop-when-empty` | Exit when queue empty |
| Job > 1 minute | Process keeps running; next cron minute fails flock (no duplicate worker) |
| Shared worker | Continues other queues on its own lock |

**Status:** Production configuration applied · Dedicated worker created · **Smoke test pending** · Audit **not resolved** until smoke OK.

---

## 3. Queue → worker inventory

| Queue | Runtime worker |
|-------|----------------|
| `seo-content-run` | Dedicated Content Project Queue Worker |
| `automation-critical` | automation-critical worker |
| `automation-external` | automation-external worker (WP publish only) |
| `automation-policy` | automation-policy worker |
| `seo-audit` | SEO maintenance worker (low concurrency) |
| `automation` | general worker |
| `seo` | general worker |
| `media_generation` | general worker |
| `default` | general worker |

---

## 4. Preflight diagnostic

```bash
cd /www/wwwroot/seo.teamviahe.com
/usr/local/lsws/lsphp83/bin/php artisan config:clear
/usr/local/lsws/lsphp83/bin/php artisan config:cache
/usr/local/lsws/lsphp83/bin/php artisan seo:queue-runtime-check
```

Expected PASS excerpt:

```text
Queue connection: database
Content Project queue: seo-content-run
Job timeout: 900
Job tries: 1
Job uniqueFor: 900
retry_after: 1200
pcntl: enabled

PASS retry_after is greater than job timeout

aaPanel queue coverage cannot be verified from Laravel source.
Expected dedicated worker queue: seo-content-run
```

Exit codes: `0` = safe, `1` = unsafe (`retry_after <= timeout`, empty run queue, unresolved connection, unreadable contract).

Command is **read-only** (no jobs table query/consume).

### `retry_after` config source

| Key | Source |
|-----|--------|
| Connection | `QUEUE_CONNECTION` → `database` |
| `retry_after` | `DB_QUEUE_RETRY_AFTER` / `config/queue.php` database fallback **1200** |

Production: previous `90` → configured target **`1200`**. Gate requires effective value **> 900**.

---

## 5. Operator smoke checklist (after deploy)

```bash
cd /www/wwwroot/seo.teamviahe.com

/usr/local/lsws/lsphp83/bin/php artisan config:clear
/usr/local/lsws/lsphp83/bin/php artisan config:cache
/usr/local/lsws/lsphp83/bin/php artisan seo:queue-runtime-check
```

If PASS: generate **one** Content Project item, then:

```bash
tail -n 200 storage/logs/content-run-queue-cron.log
/usr/local/lsws/lsphp83/bin/php artisan queue:failed
```

**Success when:** dedicated worker consumed the job · item leaves generating · no new failed job · worker process ends after one job (`--max-jobs=1`).

**Dispatch success ≠ job consumed.**

---

## 6. New queue name rule

Any task that introduces a **new** queue name is incomplete until:

- [ ] Queue name has canonical source
- [ ] Producer/job verified
- [ ] Production worker consumes that queue
- [ ] aaPanel command updated if needed
- [ ] Queue priority decided
- [ ] Docs inventory updated
- [ ] `timeout` / `retry_after` checked (`retry_after > timeout`)
- [ ] Smoke test consumed successfully

Do not merge a new queue only because a row appeared in `jobs`.

---

## 7. Log files (shell redirect — not Laravel daily channels)

| File | Writer | Rotation |
|------|--------|----------|
| `storage/logs/queue-cron.log` | general worker redirect | Not Laravel daily; **pending** host logrotate |
| `storage/logs/cron-schedule.log` | Scheduler redirect | Same |
| `storage/logs/content-run-queue-cron.log` | Dedicated CP worker redirect | Same |

This pass does **not** ship logrotate config.

---

## 8. Explicit non-claims

- Code/config remediation ≠ smoke verification.
- aaPanel remediation can be applied while audit still **Not resolved**.
- Empty `jobs` snapshot ≠ proof of consumer.
