# Automation Phase 4 Rollout — SeoContentAi

**Phase:** 4A Staging Validation (Group 1)  
**Updated:** 2026-07-18

## Scope

Migrate production callers **local-only** sang `ActionRunner` qua per-caller feature flag (`legacy` | `shadow` | `action`).

**Không migrate trong 4A:** Article Editor save, WP article sync, scheduled publish, comment review.  
Group 2 (create/content/seo_meta): **wired in 4B**, default vẫn `legacy` — xem hosting runbook.

## Feature flags

Config: `config/seo-content-ai.php` → `automation_migration.*`

| Flag key | Env | Default (repo) | Staging target |
|---|---|---|---|
| `seo_issue_assignment` | `AUTOMATION_MIGRATION_SEO_ISSUE_ASSIGNMENT` | `legacy` | bật `shadow` **trước** |
| `keyword_project_assignment` | `AUTOMATION_MIGRATION_KEYWORD_PROJECT_ASSIGNMENT` | `legacy` | shadow sau caller 1 ổn |
| `project_article_attach` | `AUTOMATION_MIGRATION_PROJECT_ARTICLE_ATTACH` | `legacy` | shadow sau keyword |
| `project_task_complete` | `AUTOMATION_MIGRATION_PROJECT_TASK_COMPLETE` | `legacy` | shadow sau cùng |
| Group 2 flags | … | `legacy` | **wired** — chưa shadow/promoted |

Thứ tự shadow (`automation_migration_shadow_order`):

1. `seo_issue_assignment`
2. `keyword_project_assignment`
3. `project_article_attach`
4. `project_task_complete`

Min samples trước promote action: `AUTOMATION_MIGRATION_MIN_PARITY_SAMPLES` (default **20**).

## Modes

| Mode | Write path | Parity |
|---|---|---|
| `legacy` | Domain/legacy only | no |
| `shadow` | Legacy ghi thật; plan/dry-run so sánh; **không** Action write | log match/mismatch |
| `action` | Chỉ ActionRunner | no legacy write |

## Parity snapshot (chuẩn hóa)

Mỗi caller normalize qua `ParitySnapshotNormalizer`:

| Field | Assignment | Attach | Complete |
|---|---|---|---|
| `ids` | project_id | task_id, article_id, site_id | task_id, article_id, site_id |
| `resulting_state` | counts | linked / task_article_id | status + task_article_id |
| `deduplication` | duplicate, already_in_project | already_attached | already_completed |
| `links` | tasks_created | article_linked | article_linked, owner_sync_expected |
| `status_transition` | null | null | to=completed |
| `changed` / `noop` | added>0 / dup|already | !already / already | !already / already |
| `wrong_context` | domain_mismatch>0 | missing ids | missing task |

Log keys:

- `automation.migration.parity_match`
- `automation.migration.parity_mismatch`
- Fields: `caller`, `action_key`, `correlation_id`, `duration_ms`, `sample`, `normalized_diff` (mismatch)
- **Không** log body/content/token/secret (`SensitivePayloadRedactor` + strip keys)

## Staging validation — sample / match / mismatch

Nguồn: unit staging scenarios (`AutomationPhase4StagingScenarioTest`) + recorder.  
**Production shadow sample:** chưa chạy trên staging thật → điền sau khi ops bật env.

| Caller | Scenario samples (unit) | Match | Mismatch | Mode hiện tại (repo default) | Promote action? |
|---|---|---|---|---|---|
| `seo_issue_assignment` | new, existing dup, partial dup, wrong context, shadow match | scenario match OK | mismatch scenario có log diff | **legacy** | **NO** — chờ ≥20 shadow staging + 0 unexplained mismatch |
| `keyword_project_assignment` | shadow mismatch log test | — | 1 (cố ý trong test) | **legacy** | **NO** |
| `project_article_attach` | new, already attached noop, wrong context | noop match OK | — | **legacy** | **NO** |
| `project_task_complete` | new, retry/already completed | noop match OK | — | **legacy** | **NO** |

### Nguyên nhân mismatch (đã biết)

| Cause | Caller | Giải thích | Block promote? |
|---|---|---|---|
| Dry-run plan ≠ legacy counts | assignment | Race / state đổi giữa plan và write | YES nếu unexplained |
| Test intentional mismatch | keyword (unit) | Verify logging | n/a |
| Attach/complete snapshot lệch `already_*` flags | attach/complete | Fixed: cả expected + legacy cùng flag | — |

### Gate không chuyển `action` nếu

- `unexplained_parity_mismatch`
- `unexplained_duplicate`
- `missing_link`
- `wp_outbound_detected`
- `new_exception`
- `insufficient_parity_samples`

Implement: `AutomationActionPromotionGate`.

## Rollout mode hiện tại

| Caller | Wired | Mode repo | Staging env khuyến nghị bước tiếp |
|---|---|---|---|
| seo_issue_assignment | yes | legacy | `=shadow` |
| keyword_project_assignment | yes | legacy | giữ legacy đến caller 1 đủ sample |
| project_article_attach | yes | legacy | sau keyword |
| project_task_complete | yes | legacy | sau attach |
| Group 2 / Editor / WP | no | — | không bật |

**Chưa** set bất kỳ caller nào sang `action` trong repo.

## Staging env — bật shadow từng bước

```bash
# Bước 1
AUTOMATION_MIGRATION_SEO_ISSUE_ASSIGNMENT=shadow

# Bước 2 (sau khi parity_match ổn)
AUTOMATION_MIGRATION_KEYWORD_PROJECT_ASSIGNMENT=shadow

# Bước 3
AUTOMATION_MIGRATION_PROJECT_ARTICLE_ATTACH=shadow

# Bước 4
AUTOMATION_MIGRATION_PROJECT_TASK_COMPLETE=shadow
```

Promote action (từng caller, sau gate):

```bash
AUTOMATION_MIGRATION_SEO_ISSUE_ASSIGNMENT=action
```

## Rollback verification

```bash
AUTOMATION_MIGRATION_<CALLER>=legacy
```

Verified in tests: `test_rollback_flag_to_legacy_verified`, `test_rollback_to_legacy_via_flag`.  
Legacy path vẫn trong bridge — không xóa.

## Tests

```text
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationPhase4
php artisan test app/Addons/SeoContentAi/tests --filter=Automation
```

Scenarios: new / existing / retry / partial duplicate / wrong context / already attached|completed.

## Group 2 — wired (default legacy)

- Flags: `project_article_create` / `content_update` / `seo_meta_update` — default **legacy**
- Bridges + planners: [`AUTOMATION_PHASE4B_PREP.md`](AUTOMATION_PHASE4B_PREP.md)
- Wired callers:
  - `CreateArticlesFromTaskService` → `ProjectArticleCreateCallerBridge`
  - `PromptTestPublishService::publishArticle` → `ProjectArticleContentCallerBridge`
  - `PromptTestPublishService::persistMetaDescription` → `ProjectArticleSeoMetaCallerBridge`
- **Chưa** migrate: Article Editor save, WP sync, scheduled publish, comment review
- Hosting: [`AUTOMATION_PHASE4B_HOSTING_VALIDATION.md`](AUTOMATION_PHASE4B_HOSTING_VALIDATION.md)
- Status: **wired** ≠ deployed ≠ shadow validated ≠ promoted

## WP paths

Untouched.
