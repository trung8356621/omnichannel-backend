# Extension SDK

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/extension-sdk/EXTENSION_SDK.md`, `CAPABILITY_SDK.md`, `PIPELINE_SDK.md`, `PUBLISHER_SDK.md`, `AI_PROVIDER_SDK.md`, `BUILTIN_WORDPRESS_EXTENSION.md`, `EXTENSION_SECURITY_BOUNDARY.md` (one module — no per-registry satellite)

## 1. Purpose

Core knows **stable contracts + registries** only. Application / Agent / CommandBus resolve publishers, AI providers, pipelines, and extension capabilities through registries/resolvers — **never** hard-code Builtin vendor classes.

```text
Core / CommandBus / Agent
        ↑
Stable Contracts
        ↑
Extension SDK (discovery + registries + resolvers)
        ↑
Extensions (Builtin/* + Extensions/{id})
```

SDK major: **1** (`SdkVersion::MAJOR`). Cutover v1.0 complete — registries are production path, not optional scaffold.

**Not the Article Editor runtime.** Phase 6A–6C `ARTICLE_EDITOR_RUNTIME.md` / `ARTICLE_EDITOR_RUNTIME_COMPLETION.md` describe an **internal** React registry for built-in editor modules. Public editor SDK ADR status: **Ready for internal stability testing** (not public SDK) — `docs/architecture/decisions/ARTICLE_EDITOR_RUNTIME_PUBLIC_SDK_READINESS.md`. Do not merge with Extension SDK v1.0 without a separate design.

## 2. Canonical routes

| Surface | Path | Role |
|---------|------|------|
| Extensions UI | `/seo/{connection_hash}/extensions` | Enable/disable, status, health payload |
| Settings sidebar | via `SeoSettingsMenu` | Discoverability |
| Config | `config/extension_sdk.php` → `seo-content-ai.extension_sdk` | Paths, discovery |
| Architecture guards | `config/seo_architecture.php` | `extension_id_pattern`, protected capability prefixes |

No marketplace UI. No upload-zip install route.

## 3. Main components

### Layout

```text
app/Addons/SeoContentAi/Extension/
  Contracts/          # Publisher, AI, SEO, Pipeline, Capability, PromptHook, Media, Workflow
  Registry/           # *Registry + ContentPlatformRegistry facade
  Builtin/Wordpress/  # publisher
  Builtin/AiProviders/
  Builtin/ContentPipelines/  # (content-pipelines extension id)
  ExtensionDiscovery.php
  ExtensionEventBus.php
  ExtensionCompatibilityChecker.php
  ExtensionHealthService.php
  ExtensionStateStore.php
```

User plugins: `app/Addons/SeoContentAi/Extensions/{id}/plugin.json` + provider class.

### Registries (single module)

| Registry | Role |
|----------|------|
| `PublisherRegistry` | `PublisherDriver` health/UI |
| `ContentPublisherRegistry` | Application `ContentPublisher` implementations |
| `AiProviderRegistry` | Chat/Image/Embedding/Moderation drivers |
| `SeoProviderRegistry` | Ahrefs/GSC/… (also settings list SoT) |
| `PipelineRegistry` | Outline/Article/Review/… step drivers |
| `ExtensionCapabilityRegistry` | Plugin-contributed Agent caps |
| `PromptHookExtensionRegistry` | Hook contributors (parallel to core loader) |
| `MediaProcessorRegistry` | Media pipeline processors |
| `WorkflowExtensionRegistry` | Workflow packs |
| `ExtensionRegistry` | Installed plugins |
| `ContentPlatformRegistry` | Facade over registries |

### Resolvers (fail-closed)

| Resolver | Builtin extension id | Error codes |
|----------|----------------------|-------------|
| `PublisherResolver` | `wordpress` (site key) | not configured / not registered / disabled |
| `AiProviderResolver` | `ai-providers` | `ai_provider.*` |
| `PipelineResolver` | `content-pipelines` | `pipeline.*` |

### Capability merge

`CanonicalCapabilityRegistry` = core `ContentProjectCapabilityRegistry` + enabled `ExtensionCapabilityRegistry` contributions. Gateway injects **Canonical** only.

## 4. Data ownership

| Store | Connection | Owner |
|-------|------------|-------|
| `seo_extension_states` | `omi_seo_ai` | enabled, status (`healthy\|error\|disabled\|needs_update`), health_payload |
| `plugin.json` on disk | filesystem | Manifest SoT for id/version/sdk/provider |
| Settings namespace | options | `extensions.{id}.*` only |

Enabled state SoT = `ExtensionStateStore` (DB/cache) — **not** `manifest.enabled`.

## 5. Read path

1. `ExtensionDiscovery` globs Builtin + Extensions paths → validate manifest → `class_exists(provider)`.
2. Compatibility check (`sdk` major) → `needs_update` / `migration_needed` if mismatch.
3. Application resolve: `PublisherResolver::resolveForSiteId`, `AiProviderResolver::assertTextReady`, `PipelineResolver` by key.
4. Agent list caps: `CanonicalCapabilityRegistry` (conflicts excluded + recorded).
5. Health: driver `health()` → `{ok, message}` — no live WP HTTP by default for builtin publisher.

## 6. Write path

```text
ExtensionProvider::register(ExtensionContext $ctx)
  → $ctx->publishers() / contentPublishers() / aiProviders() / pipelines() / capabilities()->contribute(...)

ExtensionProvider::boot(...)
  → subscribe ExtensionEventBus / warm caches
```

**Builtin WordPress**

```text
WordpressExtensionProvider
  → contentPublishers()->register('wordpress', WordPressPublisher)
  → publishers()->register('wordpress', WordpressPublisherDriver)
```

Site maps via `seo_publisher_key` / `seo_platform` (default wordpress). No silent fallback to WordPress when unconfigured.

**Events:** `ContentProjectDomainEvents::dispatchAfterCommit` → `ExtensionEventBus` (per-listener try/catch). Bridged: `content_project.created|items_generated|published|archived`.

## 7. Public capabilities

- List/enable/disable extensions (manager UI).
- Plugin-contributed Agent/MCP caps after Canonical merge + exposure flags (`isAgentWriteExposed` / `isMcpWriteExposed`).
- Example plugin cap names: `seo.audit`, `gsc.sync`, `social.publish` (not core-prefixed).

## 8. Internal-only capabilities

| Item | Notes |
|------|-------|
| Discovery / make provider | Service container after `class_exists` |
| Registry register APIs | Only inside `ExtensionProvider::register` |
| `ExtensionHealthService` | Ops/UI |
| PromptHook extension contributors | Do not replace core `PromptHookDefinitionLoader` wholesale |
| Pipeline step drivers | Stage scaffold; wiring into CP run is domain-owned |

## 9. Authorization and confirmation

- Extension id must match `/^[a-z0-9][a-z0-9._-]*$/`.
- Settings writes only under `extensions.{id}.*` — never core `seo_project_agent.*` / `seo_content_ai.*`.
- Events/health: no API keys, tokens, passwords, connection strings, full article bodies.
- Agent must not import `Extension\Builtin\*`.

## 10. Queue and scheduler ownership

Extension SDK has **no** dedicated cron. Side effects after domain events are in-process bus (after commit). Long work stays in owning module queues (CP run, Site Sync, Automation).

## 11. Transactions and side effects

- Extension listeners run **after** domain commit — listener failure cannot rollback domain or block other listeners.
- Publisher `publish`/`update`/`delete` are domain-invoked via Resolver from Application handlers — not from Agent layer directly.
- WordPress publisher idempotent on `external_reference` / `wp_post_id` (at-least-once reconcile before create).

## 12. Retry and recovery

- Resolver fail-closed: fix config / enable extension / register driver — no silent Builtin substitute.
- Status `error` when provider class missing — discovery continues for others.
- SDK major mismatch → `needs_update`; do not force-load incompatible major.

## 13. Compatibility paths

- Dual registration WordPress: `ContentPublisher` (Application) + `PublisherDriver` (SDK health/UI).
- SEO provider settings list may still use `Services/SeoProviderRegistry` aligned with Extension SEO registry contract.
- Historical per-file SDK docs under `docs/archive/extension-sdk/` — not live SoT.

## 14. Forbidden paths

- Marketplace / auto-download / zip upload / remote PHP / `eval` / dynamic `include` from untrusted path.
- Application Handlers importing `Extension\Builtin\Wordpress\WordPressPublisher` (or resurrecting `Application/Publishing/WordPressContentPublisher`).
- Agent importing Builtin extension classes.
- Plugin registering capability under protected `content_project.` prefix or injecting internal commands (`process_scheduled_publish`, stop/resume).
- Override core capability names via extension (conflicts excluded).
- Credentials in event envelopes or health payloads.
- Silent publisher fallback to WordPress when site unconfigured.

## 15. Tests and invariants

| Invariant | Test |
|-----------|------|
| No eval/arbitrary include in discovery | `ExtensionSdkFoundationTest` |
| Architecture freeze (handlers/resolvers) | `ExtensionArchitectureFreezeTest` |
| Capability merge / conflicts | Canonical registry tests + Agent gateway contracts |
| `extension_id_pattern` | `config/seo_architecture.php` + freeze tests |

**Invariants:** local disk only; resolve via registry; Canonical for Agent; after-commit isolated listeners; namespace settings; fail-closed resolvers.

## 16. Related documents

- `docs/contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md`
- `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`
- `docs/modules/PROMPTS_AND_AI.md`
- `docs/modules/PUBLISHING.md`
- `docs/modules/CONTENT_PROJECTS.md`
- `docs/architecture/ARCHITECTURE_FREEZE_V1.md` (if present)
)
