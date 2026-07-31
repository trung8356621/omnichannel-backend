> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Phase 4 Rollout â€” SeoContentAi

**Phase:** 4A Staging Validation (Group 1)  
**Updated:** 2026-07-18

## Scope

Migrate production callers **local-only** sang `ActionRunner` qua per-caller feature flag (`legacy` | `shadow` | `action`).

**KhÃ´ng migrate trong 4A:** Article Editor save, WP article sync, scheduled publish, comment review.  
Group 2 (create/content/seo_meta): **wired in 4B**, default váº«n `legacy` â€” xem hosting runbook.

## Feature flags

Config: `config/seo-content-ai.php` â†’ `automation_migration.*`

| Flag key | Env | Default (repo) | Staging target |
|---|---|---|---|
| `seo_issue_assignment` | `AUTOMATION_MIGRATION_SEO_ISSUE_ASSIGNMENT` | `legacy` | báº­t `shadow` **trÆ°á»›c** |
| `keyword_project_assignment` | `AUTOMATION_MIGRATION_KEYWORD_PROJECT_ASSIGNMENT` | `legacy` | shadow sau caller 1 á»•n |
| `project_article_attach` | `AUTOMATION_MIGRATION_PROJECT_ARTICLE_ATTACH` | `legacy` | shadow sau keyword |
| `project_task_complete` | `AUTOMATION_MIGRATION_PROJECT_TASK_COMPLETE` | `legacy` | shadow sau cÃ¹ng |
| Group 2 flags | â€¦ | `legacy` | **wired** â€” chÆ°a shadow/promoted |

Thá»© tá»± shadow (`automation_migration_shadow_order`):

1. `seo_issue_assignment`
2. `keyword_project_assignment`
3. `project_article_attach`
4. `project_task_complete`

Min samples trÆ°á»›c promote action: `AUTOMATION_MIGRATION_MIN_PARITY_SAMPLES` (default **20**).

## Modes

| Mode | Write path | Parity |
|---|---|---|
| `legacy` | Domain/legacy only | no |
| `shadow` | Legacy ghi tháº­t; plan/dry-run so sÃ¡nh; **khÃ´ng** Action write | log match/mismatch |
| `action` | Chá»‰ ActionRunner | no legacy write |

## Parity snapshot (chuáº©n hÃ³a)

Má»—i caller normalize qua `ParitySnapshotNormalizer`:

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
- **KhÃ´ng** log body/content/token/secret (`SensitivePayloadRedactor` + strip keys)

## Staging validation â€” sample / match / mismatch

Nguá»“n: unit staging scenarios (`AutomationPhase4StagingScenarioTest`) + recorder.  
**Production shadow sample:** chÆ°a cháº¡y trÃªn staging tháº­t â†’ Ä‘iá»n sau khi ops báº­t env.

| Caller | Scenario samples (unit) | Match | Mismatch | Mode hiá»‡n táº¡i (repo default) | Promote action? |
|---|---|---|---|---|---|
| `seo_issue_assignment` | new, existing dup, partial dup, wrong context, shadow match | scenario match OK | mismatch scenario cÃ³ log diff | **legacy** | **NO** â€” chá» â‰¥20 shadow staging + 0 unexplained mismatch |
| `keyword_project_assignment` | shadow mismatch log test | â€” | 1 (cá»‘ Ã½ trong test) | **legacy** | **NO** |
| `project_article_attach` | new, already attached noop, wrong context | noop match OK | â€” | **legacy** | **NO** |
| `project_task_complete` | new, retry/already completed | noop match OK | â€” | **legacy** | **NO** |

### NguyÃªn nhÃ¢n mismatch (Ä‘Ã£ biáº¿t)

| Cause | Caller | Giáº£i thÃ­ch | Block promote? |
|---|---|---|---|
| Dry-run plan â‰  legacy counts | assignment | Race / state Ä‘á»•i giá»¯a plan vÃ  write | YES náº¿u unexplained |
| Test intentional mismatch | keyword (unit) | Verify logging | n/a |
| Attach/complete snapshot lá»‡ch `already_*` flags | attach/complete | Fixed: cáº£ expected + legacy cÃ¹ng flag | â€” |

### Gate khÃ´ng chuyá»ƒn `action` náº¿u

- `unexplained_parity_mismatch`
- `unexplained_duplicate`
- `missing_link`
- `wp_outbound_detected`
- `new_exception`
- `insufficient_parity_samples`

Implement: `AutomationActionPromotionGate`.

## Rollout mode hiá»‡n táº¡i

| Caller | Wired | Mode repo | Staging env khuyáº¿n nghá»‹ bÆ°á»›c tiáº¿p |
|---|---|---|---|
| seo_issue_assignment | yes | legacy | `=shadow` |
| keyword_project_assignment | yes | legacy | giá»¯ legacy Ä‘áº¿n caller 1 Ä‘á»§ sample |
| project_article_attach | yes | legacy | sau keyword |
| project_task_complete | yes | legacy | sau attach |
| Group 2 / Editor / WP | no | â€” | khÃ´ng báº­t |

**ChÆ°a** set báº¥t ká»³ caller nÃ o sang `action` trong repo.

## Staging env â€” báº­t shadow tá»«ng bÆ°á»›c

```bash
# BÆ°á»›c 1
AUTOMATION_MIGRATION_SEO_ISSUE_ASSIGNMENT=shadow

# BÆ°á»›c 2 (sau khi parity_match á»•n)
AUTOMATION_MIGRATION_KEYWORD_PROJECT_ASSIGNMENT=shadow

# BÆ°á»›c 3
AUTOMATION_MIGRATION_PROJECT_ARTICLE_ATTACH=shadow

# BÆ°á»›c 4
AUTOMATION_MIGRATION_PROJECT_TASK_COMPLETE=shadow
```

Promote action (tá»«ng caller, sau gate):

```bash
AUTOMATION_MIGRATION_SEO_ISSUE_ASSIGNMENT=action
```

## Rollback verification

```bash
AUTOMATION_MIGRATION_<CALLER>=legacy
```

Verified in tests: `test_rollback_flag_to_legacy_verified`, `test_rollback_to_legacy_via_flag`.  
Legacy path váº«n trong bridge â€” khÃ´ng xÃ³a.

## Tests

```text
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationPhase4
php artisan test app/Addons/SeoContentAi/tests --filter=Automation
```

Scenarios: new / existing / retry / partial duplicate / wrong context / already attached|completed.

## Group 2 â€” wired (default legacy)

- Flags: `project_article_create` / `content_update` / `seo_meta_update` â€” default **legacy**
- Bridges + planners: [`AUTOMATION_PHASE4B_PREP.md`](AUTOMATION_PHASE4B_PREP.md)
- Wired callers:
  - `CreateArticlesFromTaskService` â†’ `ProjectArticleCreateCallerBridge`
  - `PromptTestPublishService::publishArticle` â†’ `ProjectArticleContentCallerBridge`
  - `PromptTestPublishService::persistMetaDescription` â†’ `ProjectArticleSeoMetaCallerBridge`
- **ChÆ°a** migrate: Article Editor save, WP sync, scheduled publish, comment review
- Hosting: [`AUTOMATION_PHASE4B_HOSTING_VALIDATION.md`](AUTOMATION_PHASE4B_HOSTING_VALIDATION.md)
- Status: **wired** â‰  deployed â‰  shadow validated â‰  promoted

## WP paths

Untouched.
