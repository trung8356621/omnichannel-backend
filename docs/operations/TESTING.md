# Testing (operations)

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/TESTING.md`, Site Sync playbook command dumps, hosting validation lists from `docs/archive/automation/*` (pointers only)

## PHPUnit discovery

Project uses **PHPUnit 11** (`phpunit/phpunit`). Do not use `php artisan test` as the project standard.

```text
$PHP_BIN vendor/bin/phpunit --filter=ClassOrMethodName
$PHP_BIN vendor/bin/phpunit app/Addons/SeoContentAi/tests/Unit
```

`php artisan optimize:clear` does **not** fix “No tests found” — that is discovery/config, not config cache.

### Paths

| Kind | Path | Namespace |
|------|------|-----------|
| Core Unit/Feature | `tests/Unit`, `tests/Feature` | `Tests\…` |
| SeoContentAi Unit | `app/Addons/SeoContentAi/tests/Unit` | `App\Addons\SeoContentAi\Tests\Unit\…` |
| SeoContentAi Feature | `app/Addons/SeoContentAi/tests/Feature` | `App\Addons\SeoContentAi\Tests\Feature\…` |

Composer PSR-4 maps SeoContentAi Tests under `autoload` (not only autoload-dev) for Linux case-sensitivity.

Doctor: `php artisan test:doctor` / `scripts/test-doctor.php` — see this file on failure.

## Site Sync

| Focus | Filter |
|-------|--------|
| Schema / steps / keyword priority / domain-save invariant | `SiteSyncV2ArchitectureFreezeTest` |
| V2 writer must not dual-apply push enrich | `SiteSyncCompatPushOwnershipContractTest` |
| Ops Center Site Sync tab + hidden nav page | `ContentProjectOperationsCenterTest` |
| Unique step/inbound job ids | `ArchitectureHardeningLockContractTest` |
| Force-full / score / wave / cutover freezes | `SiteSyncV2ForceFullFreezeTest`, `SiteSyncV2ScorePipelineFreezeTest`, `SiteSyncV2Wave2FreezeTest`, `SiteSyncV2Wave3PatchIntegrityTest`, `SiteSyncV2Wave4CutoverFreezeTest` |
| Agent `/site-sync*` CLI mapping | `AgentMcpSiteCliFixTest` |

Canonical module: [../modules/SITE_SYNC.md](../modules/SITE_SYNC.md) §15.

## WordPress Bridge

| Focus | Filter |
|-------|--------|
| Compat push ownership | `SiteSyncCompatPushOwnershipContractTest` |

Canonical: [../modules/WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md) §15.

## Automation / Extension / CP

| Focus | Filter |
|-------|--------|
| Automation ownership / dispatcher | `AutomationDispatcherOwnershipContractTest` |
| Architecture hardening locks | `ArchitectureHardeningLockContractTest` |
| Backend risk closure | `BackendRiskClosureContractTest` |
| Content project item state | `ContentProjectItemStateContractTest`, `ContentProjectItemStateResolverTest` |
| Rerun unify | `ContentProjectRerunUnifyTest` |
| Agent workspace freeze doc | `AgentWorkspaceV1SweepTest` |
| Ops center metrics/docs | `ContentProjectOperationsCenterTest` |
| Agent planner contracts | `ContentProjectAgentPlannerTest` |

## RuntimeLogger

HTTP paths must not write default `laravel.log`. Prefer contract tests covering `RuntimeLogger` / web_app channel where present.

## Related

- [SCHEDULER_AND_WORKERS.md](SCHEDULER_AND_WORKERS.md)
- [DEPLOYMENT.md](DEPLOYMENT.md)
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- [../modules/SITE_SYNC.md](../modules/SITE_SYNC.md)
- [../modules/WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md)
- [../modules/AUTOMATION.md](../modules/AUTOMATION.md)
- [../modules/PROMPTS_AND_AI.md](../modules/PROMPTS_AND_AI.md)
- [../modules/EXTENSION_SDK.md](../modules/EXTENSION_SDK.md)
- [../modules/OPERATIONS_AND_OBSERVABILITY.md](../modules/OPERATIONS_AND_OBSERVABILITY.md)
- [../README.md](../README.md)
