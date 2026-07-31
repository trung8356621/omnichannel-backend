> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../operations/TESTING.md
> Purpose: implementation history only
# Legacy test audit (2026-07-25)

Discovery baseline: **265** `*Test.php` / **265** discovered / Issues **0** (`test:doctor`).

PHPUnit suites (`phpunit.xml`):

| Suite | Tests (list) |
|-------|--------------|
| Unit | 82 |
| Feature | 9 |
| SeoContentAiUnit | 1276 |
| SeoContentAiFeature | 2 |
| **Total** | **1369** |

## Server commands (agent cannot SSH)

```bash
cd /www/wwwroot/seo.teamviahe.com
COMPOSER_ALLOW_SUPERUSER=1 composer install
COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload

# Prefer scripts (512M default):
php scripts/test-doctor.php
php scripts/run-phpunit.php --testsuite Unit
php scripts/run-phpunit.php --testsuite Feature
php scripts/run-phpunit.php --testsuite SeoContentAiUnit
php scripts/run-phpunit.php --testsuite SeoContentAiFeature
php scripts/run-phpunit.php app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php

# Full suite (long). Override RAM if needed:
TEST_MEMORY_LIMIT=1G php scripts/run-phpunit.php

# Real MySQL SEO DB (optional; default testing uses sqlite remap):
SEO_TEST_USE_MYSQL=true php scripts/run-phpunit.php --testsuite SeoContentAiUnit
```

**KhÃ´ng** cháº¡y `php artisan test:ci`. DÃ¹ng `composer test:ci` hoáº·c `php scripts/...`.

Báº­t `ext-fileinfo` trÃªn PHP 8.3 CLI trÆ°á»›c `composer install`. Giá»¯ `composer.lock`.

## Root-cause buckets (SeoContentAiUnit JUnit, local)

Source: `storage/app/testing-audit/seo-unit.xml`  
Summary: Tests **1276**, Errors **104**, Failures **43**, Skipped **21**, Risky **5**.

| Bucket | Files | Meaning | Action |
|--------|------:|---------|--------|
| ENV_MYSQL | 25 | `SQLSTATE[HY000] [2002]` MySQL refused | FIX_INFRASTRUCTURE â€” TestCase sqlite remap (default); server set `SEO_TEST_USE_MYSQL=true` when DB up |
| CONFIG | 8 | `Target class [config] does not exist` | FIX_INFRASTRUCTURE â€” switch to `Tests\TestCase` |
| FINAL (heuristic) | ~6â€“20 | Mockery/PHPUnit mock on `final` class **or** source assert containing word `final` | UPDATE_STALE / don't mock final |
| FAIL (assertion) | ~19 | Stale UI/source/behavior expectations | UPDATE_STALE or PRODUCTION_BUG |
| SKIP | 5 | Existing `markTestSkipped` / UsesSeoDatabase | FIX_INFRASTRUCTURE (forbid new skips) |
| OTHER | 4 | Mixed | triage |

## Infrastructure fixes already landed (this cleanup wave)

| Change | Why |
|--------|-----|
| `tests/TestCase.php` â€” remap `core_connection` + `omi_seo_ai` to default sqlite unless `SEO_TEST_USE_MYSQL=true` | Stop mass ENV_MYSQL cascade without live MySQL |
| `afterApplicationCreated` before DB traits | Remap must run before `DatabaseTransactions` |
| `User` + `HasFactory`; `UserFactory` role/status/seo_role | Feature Auth factory errors |
| `routes/web.php` `require auth.php` | Auth routes existed but were not loaded (real routing bug) |
| `RuntimeLoggerWebAppChannelTest` asserts updated | STALE brittle string checks |
| 6 CONFIG tests â†’ `Tests\TestCase` | ArticleMetaMap, PostTypeResolver, WorkflowParser, LinkSuggestion*, ProductReviewAutomationPublish |
| `scripts/run-phpunit.php` default `memory_limit=512M` (`TEST_MEMORY_LIMIT` override) | Phase 5 |

## Current local verify (after infra)

| Suite / file | Result |
|--------------|--------|
| Unit | **OK** 82 tests |
| Feature | **OK** 9 tests |
| ArticlePipelineRerunServiceTest | **OK** 15 tests |
| ArticleMetaMapTest (after TestCase switch) | **OK** |
| `test:doctor --skip-list` | Issues **0** |

## File inventory (failing Seo Unit â€” action map)

Status codes per Phase 6: `KEEP_VALID` | `UPDATE_STALE` | `FIX_INFRASTRUCTURE` | `DELETE_REMOVED_FEATURE` | `MERGE_DUPLICATE` | `PRODUCTION_BUG`

### ENV_MYSQL â†’ FIX_INFRASTRUCTURE (keep tests; rely on TestCase / server MySQL)

| File | Status | Root cause | Action | Evidence |
|------|--------|------------|--------|----------|
| ArticleContentProjectOptionsTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep; re-run after TestCase remap / SEO_TEST_USE_MYSQL | JUnit PDO 2002 |
| ArticleEditorSavePatchServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| ArticleKeywordLinkReconcileServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| ArticlePendingInternalLinkServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| ArticleScheduleReconcileServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| CtaKeywordBlacklistDebugServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| KeywordDebugRescrapeServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| KeywordFocusResyncTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| KeywordLinkDetailPanelPresenterTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| KeywordPersistenceServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 / txn order | Keep; verify after `afterApplicationCreated` | JUnit |
| KeywordPhraseReconcileTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| KeywordQualityFlagServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| KeywordReviewServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| LinkAuditCacheTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| SeoArticleRevisionRestoreServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| SeoArticleRevisionServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| SeoMediaStorageServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| SeoProjectRunConsolidationServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| SeoProjectRunItemSchemaPhase2Test.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| SeoToolsAccessControlTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| SyncSingleArticleFromWordPressTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| TagPersistenceServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| TeamChatNotificationServiceTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| TeamMessageControllerTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |
| WpPushLifecycleImportTest.php | FIX_INFRASTRUCTURE | MySQL 2002 | Keep | JUnit |

### CONFIG â†’ FIX_INFRASTRUCTURE (switched to Tests\TestCase)

| File | Status | Root cause | Action | Evidence |
|------|--------|------------|--------|----------|
| ArticleMetaMapTest.php | FIX_INFRASTRUCTURE | PHPUnit TC + Eloquent/config | Switched â†’ Tests\TestCase; **pass** spot-check | `Support/ArticleMetaMap.php` |
| ArticlePostTypeResolverTest.php | FIX_INFRASTRUCTURE | same | Switched | `Support/ArticlePostTypeResolver.php` |
| WorkflowParserServiceTest.php | FIX_INFRASTRUCTURE | `app()`/scoring registry | Switched | `SeoScoringRulesRegistry` |
| ArticleLinkSuggestionHardeningTest.php | FIX_INFRASTRUCTURE | `config()` | Switched | SearchTermsBuilder |
| ArticleLinkSuggestionContentFallbackTest.php | FIX_INFRASTRUCTURE | `config()` | Switched | PhraseExtractor |
| ProductReviewAutomationPublishTest.php | FIX_INFRASTRUCTURE | `now()`/app | Switched | PayloadFactory |

### FINAL mock â†’ UPDATE_STALE (do not delete; stop mocking finals)

| File | Status | Root cause | Action | Evidence |
|------|--------|------------|--------|----------|
| ArticleContentGenerateRewriteHookTest.php | UPDATE_STALE | mock final `PromptRunnerService` | Inject interface/fake compile; keep FakeProvider | `PromptRunnerService` final |
| OutlineHookVerticalSliceTest.php | UPDATE_STALE | same | same | final |
| PromptHookExecuteControllerTest.php | UPDATE_STALE | Mockery on final `PromptHookExecutionService` | Container fake / interface | final |
| WordpressSyncAutomationOutcomeIsolationTest.php | UPDATE_STALE | mock final sync/dispatcher/emitter | Contracts or reflection seams | finals |
| ArticleOutlineRetryDependencyTest.php | UPDATE_STALE | mock final catalog | Thin adapter / real catalog | `SeoProjectWorkflowStepCatalogService` final |
| GeminiMediaGenerationImagenRetryTest.php | UPDATE_STALE | Mockery finals + wrong import | Real Support\ImageRoutingStrategy; no mock storage | `GeminiMediaGenerationService` final |

### Assertion FAIL â€” triage

| File | Status | Root cause | Action | Evidence |
|------|--------|------------|--------|----------|
| PromptExecutionOrchestrationTest.php | PRODUCTION_BUG | `failPrepared` missing `ACTIVE_STATUSES` guard string | **Keep**; fix production later | assert on `SeoProjectWorkflowRunService` |
| LegacyProjectRunItemMapperTest.php | UPDATE_STALE or PRODUCTION_BUG | expect `article.update` got `article.rewrite` | Verify mapper vs product spec | mapper output |
| AutomationPhase4* / Phase4B* | UPDATE_STALE | mode legacy vs action after cutover | Update expectations to current automation mode | staging scenarios |
| ArticleEditor* source/JS asserts | UPDATE_STALE | UI refactors | Update fixtures to current Blade/JS | editor components |
| PromptHookDocumentationTest.php | UPDATE_STALE | doc front-matter version drift | Sync docs or expect current version | hook docs |
| QuickSplitCanvasAndWarningTest.php | UPDATE_STALE | copy/grid mismatch message | Match current injector copy | QuickSplit |
| SeoImageOptimizationServiceTest.php | UPDATE_STALE / ENV | transparent PNG / Imagick | Check extension + expectation | service |
| SeoProjectPhase3C1LifecycleTest.php | UPDATE_STALE | Livewire method surface changed | Update to current public API | Filament pages |
| WordPressArticleSyncStatusTest.php | UPDATE_STALE | payload size 3 vs 2 | Align with current optional fields | sync status |
| GoogleSearchConsoleOAuthTest.php | UPDATE_STALE / ENV | OAuth wiring | Re-read current OAuth service | GSC |
| GeminiModelVersionPolicyTest.php | UPDATE_STALE | model allow-list drift | Update to registry | policy |
| ContentProjectRunEnginePhase1Test.php | UPDATE_STALE | event contract list | Sync RunEngine events | RunEngine |
| ImagenProviderErrorClassifierTest.php | UPDATE_STALE | hardcoded Vertex id assert | Align classifier + source | classifier test |
| ArticleLastSavedTimestampWiringTest.php | UPDATE_STALE | migration source assert | Match current migration | migrations |
| ArticleEditorMountNoRemoteWpTest.php | UPDATE_STALE | hydrate must not call WP | Update source contract | EditArticle |
| ArticleEditorStickyHeaderHelpTest.php | UPDATE_STALE | help modal selectors | Update Blade selectors | help modal |
| ArticleEditorModuleSyncQueueHardeningTest.php | UPDATE_STALE | syncWp close_editor flag | Update controller contract | sync controller |

### SKIP files (existing skips â€” do not add more)

| File | Status | Notes |
|------|--------|-------|
| BusinessHookSmokeTest.php | FIX_INFRASTRUCTURE | Uses skips; make runnable under sqlite/mysql |
| BusinessHookSmokeHardeningTest.php | FIX_INFRASTRUCTURE | same |
| SeoAnalyzerScoringTest.php | FIX_INFRASTRUCTURE | skips |
| SeoAuditCacheArchitectureTest.php | FIX_INFRASTRUCTURE | skips |

### DELETE_REMOVED_FEATURE candidates

**None confirmed yet.** Controllers/routes/services for failing tests still exist. Auth routes were **missing require** (restored), not deleted feature.

Do **not** mass-delete on â€œfailingâ€ alone.

## SeoUnit after infra remap (wave 1)

| Metric | Before | After TestCase remap |
|--------|-------:|---------------------:|
| Errors | 104 | 109 (pre refreshApplication fix) |
| Failures | 43 | 44 |
| ENV_MYSQL files | 25 | 20 â†’ **0 connection-refused** after `refreshApplication()` remap (spot: KeywordPersistence now `no such table` = sqlite without SEO migrations) |
| CONFIG files | 8 | 1 |

**Infra lesson:** `afterApplicationCreated` cháº¡y **sau** `setUpTraits` â†’ quÃ¡ muá»™n cho `DatabaseTransactions`. Remap trong `refreshApplication()`.

Local without MySQL: connection OK (sqlite) nhÆ°ng thiáº¿u schema SEO â†’ wave 2 migrate `app/Addons/SeoContentAi/database/migrations` lÃªn `omi_seo_ai` sqlite **hoáº·c** server dÃ¹ng `SEO_TEST_USE_MYSQL=true`.

Core **Unit 82 OK**, **Feature 9 OK**, **ArticlePipelineRerunServiceTest OK**, doctor Issues **0**.


| Metric | Value |
|--------|------:|
| Total test files | 265 |
| Total methods (suites) | 1369 |
| Core Unit pass (post-fix) | 82/82 |
| Core Feature pass (post-fix) | 9/9 |
| Seo Unit fail files (pre-remap JUnit) | ~72 |
| ENV_MYSQL files | 25 |
| CONFIG files (patched) | 6 |
| FINAL mock files (confirmed) | 6 |
| PRODUCTION_BUG kept | â‰¥1 (`PromptExecutionOrchestrationTest`) |
| Files deleted this wave | **0** |

## Next cleanup wave (ordered)

1. Re-run `SeoContentAiUnit` after TestCase remap; expect ENV_MYSQL collapse.
2. Finish FINAL_MOCK rewrites (no mock final).
3. Update STALE editor/automation source asserts against current files.
4. Keep PRODUCTION_BUG list; do not greenwash.
5. Revisit SKIP smoke tests â†’ real isolation.
6. Only then consider DELETE when `rg` proves symbol gone.

## Manual verification (server)

```text
php -m | grep fileinfo
COMPOSER_ALLOW_SUPERUSER=1 composer install
COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload
php scripts/test-doctor.php
php scripts/run-phpunit.php --testsuite Unit
php scripts/run-phpunit.php --testsuite Feature
php scripts/run-phpunit.php app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
TEST_MEMORY_LIMIT=512M php scripts/run-phpunit.php --testsuite SeoContentAiUnit
```
