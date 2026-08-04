# Prompts and AI

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/MAP_SEO_SETTINGS.md` (prompts/settings/AI slices), `docs/archive/maps/MAP_SEO_SETTINGS.md`, `docs/archive/prompts/*`, `docs/archive/automation/prompt/*` (durable ownership/runtime only — not phase rollout dumps), `docs/archive/extension-sdk/AI_PROVIDER_SDK.md`

## 1. Purpose

Canonical map for **prompt ownership**, **hook contracts**, **AI provider resolve**, Filament settings/API surfaces, and forbidden dual-write.

Layers (must stay separate):

| Layer | Owns |
|-------|------|
| **Hook** | Capability/contract (`key@version`); input/output schema; templates locale; normalize |
| **Settings binding** | `prompt_hook_bindings` map `hook_key → prompt_id` (runtime SoT for settings-visible hooks) |
| **Task-owned prompt** | `prompt_id` on workflow graph Prompt Block / SeoTask node |
| **Provider** | `AiProviderResolver` → registry drivers (no vendor hard-code in PromptRunner) |
| **Domain write** | Business Action / Workflow action node / UI apply — **never** Hook Engine |

Unassigned Prompt (no Hook, no binding, no Task ref) is valid storage — does **not** auto-run.

**Article Editor (Phase 6C.4):** in-editor AI Chat (image/video generate + selection) is React runtime module `article-editor.ai`. Prompt configuration, AI history, billing/audit stay Filament/Laravel shell. See [`ARTICLE_EDITOR_RUNTIME_COMPLETION.md`](../architecture/ARTICLE_EDITOR_RUNTIME_COMPLETION.md). Legacy ModuleHost cleanup: [`ARTICLE_EDITOR_LEGACY_CLEANUP.md`](../architecture/ARTICLE_EDITOR_LEGACY_CLEANUP.md).

## 2. Canonical routes

Panel prefix: `/seo/{connection_hash}/`

| Path | Page / role |
|------|-------------|
| `settings` | `SeoSettings` redirect → overview |
| `settings/overview` | `SeoSettingsOverview` — model status, team chat limits |
| `settings/workflows` | `SeoSettingsWorkflows` — task workflows + prompt hook bindings |
| `settings/ai-advanced` | `SeoSettingsAiAdvanced` — image/typography/video routing |
| `settings/editor` | `SeoSettingsEditor` — local draft interval, undo, FAQ catch |
| `settings/keywords` | `SeoSettingsKeywords` — CTA blacklist, review reasons |
| `settings/prompt` | `SeoSettingsPrompt` — default prompts / system prompts |
| `settings/scoring` | `SeoSettingsScoring` — SEO scoring rule overrides |
| `settings/recommendations` | `SeoSettingsRecommendations` — docs-only (no runtime) |
| `settings/api` | `AiConnectionResource` — API connections (AI + SEO providers) |
| `settings/ai` | Legacy redirect → `settings/api` |
| `prompts` | `PromptResource` — Prompt management |
| `extensions` | Extension states UI (provider health) |
| `image-optimization` | `ImageOptimizationSettings` — site-aware media opts |

**HTTP**

| Method | Path | Role |
|--------|------|------|
| POST | `/api/seo/prompt-hooks/{hookKey}/execute` | `PromptHookExecuteController` — AI only; **no** article/SEO/WP save |

Gates: Manager for most settings (`canAccessManagerFeatures`); Prompt CRUD planner + mutation (`canAccessPlannerFeatures` / `allowsSeoPanelMutation`).

## 3. Main components

| Concern | Class |
|---------|--------|
| Prompt model | `Models/SeoPrompt` (`prompts`, `omi_seo_ai`) |
| Prompt CRUD | `Filament/Resources/PromptResource` |
| Workflows + bindings | `SeoSettingsWorkflows` + `SeoCreateArticleSettingsService` |
| Binding resolve | `SettingsPromptBindingResolver` |
| Hook catalog (UI) | `PromptHookEditorCatalog` |
| Hook execute (Phase-1 path) | `PromptHookExecutionService` |
| Hook runtime engine | `PromptHooks/Runtime/*` (`PromptHookCallerBridge`, DefinitionLoader, …) |
| Manifest load | `resources/prompt-hooks/*.json` + `PromptHooks/` |
| Entity context | `ArticlePromptHookEntityResolver` (array context only) |
| AI run engine | `PromptRunnerService` |
| Model route/failover | `AiModelRouterService` + `GeminiModelVersionPolicy` |
| Provider resolve | `Extension/…/AiProviderResolver` + `AiProviderRegistry` |
| Builtin AI drivers | `Extension/Builtin/AiProviders/` (`GeminiAiTextProvider`, `ClaudeAiTextProvider`) |
| Claude exec | `AiExecutionService` |
| Image gen | `MediaGenerationService`, `ImageRoutingStrategy` |
| Image output mode | `ImageOutputModePromptInjector` |
| PromptResult attach | `PromptResultAttachService` / action `prompt_result.attach` |
| Typed workflow artifacts | `WorkflowArtifactType` + `WorkflowTypedArtifact` + `ArtifactReusePolicy` — PromptResult is audit only; domain write consumes declared typed deps |
| Usage / delete safety | `PromptUsageLocator`, `PromptDeleteGuard` |
| Prompt Editor — Runtime Rules UI | `PromptRuntimeRulesPresenter` — readonly panel from Hook definition + `PromptOutputContractCatalog` (no compose, no user markdown). Bound via `SeoContentAiServiceProvider` (`PromptOutputContractCatalog` / `PromptOutputContractResolver` singletons). |
| Prompt Editor — preview toggle | `PromptCompositionSummaryPresenter` — default Runtime Rules; `Show Effective Prompt (Debug)` ON → full compose via `PromptHookCompositionPreviewService`. |
| Prompt Editor form | `PromptResource` — MarkdownEditor `minHeight(280px)` + `maxHeight(600px)` (EasyMDE single scrollbar); section title Runtime Rules (Built-in). |
| Default comment | `DefaultCommentPromptInstaller` + hook `article.comment.generate` |
| API connections list | `ApiConnectionsListService` + `AiConnectionResource` |
| SEO provider matrix | `SeoProviderRegistry` + `SeoProviderCapabilityResolver` |
| Outline input any-of | `article.outline.generate` — `post_title` and `keyword` individually optional; `metadata.require_any_of` enforced by `PromptHookRequireAnyOf` / ExplicitBinding. Project item requires at least one of keyword or post_title. Both may be provided. AI outline/article generation may generate or optimize the final title. |
| Outline output normalize | `MarkdownSectionsOutputParser::normalizeProviderRaw` — strip BOM, unwrap outer fence, drop short prologue/epilogue; still reject between-section prose, duplicates, missing markers, undeclared task markers. Downstream writing skipped (not failed) when outline fails. |
| Topic input (schema-gated) | `PromptHookExplicitBindingExecutor::enrichTopicInput` + `mapInput` whitelist — injects runtime `topic` only when **current** hook `input_schema` declares `topic`. Legacy compile uses schema-whitelisted `$input` only (no shared-payload merge). Must not leak into `article.content.generate` / comment / FAQ → `Unknown input key [topic]`. |
| Outline fail → write skip | `TaskWorkflowTestRunner::run` marks content/write steps `skipped` with “Không chạy vì bước Dàn ý thất bại.” — not Failed missing-outline. |
| Content fail → persist block | Persist action `blocked` when no valid `article_content` artifact — never fallback to outline / latest PromptResult. |

## 4. Data ownership

| Store | Connection | SoT |
|-------|------------|-----|
| `prompts` | `omi_seo_ai` | Prompt body, variables, `ai_connection_id`, optional hook key |
| Settings option (workflows) | site/option JSON | `prompt_hook_bindings` **runtime SoT**; task keys `KEY_*_TASK` |
| Legacy option keys (`article_title_suggestion_prompt_id`, …) | same JSON | Rollback / migrate-on-read **only** — not runtime write SoT |
| `prompt_results` / `seo_prompt_result_links` | `omi_seo_ai` | Audit artifacts + domain attach |
| `api_connections` / `seo_ai_models` | core `mysql` | AI credentials + synced models |
| GSC / DataForSEO / SERP / extended | core `mysql` | External SEO provider rows |
| Hook JSON manifests | disk | Contract schemas; **no secrets** |
| Locale templates | `lang/{vi,en}/prompt_hooks.php` | Labels + template text |

**Hook ownership**

| Flag | Meaning |
|------|---------|
| `settings_visible=true` | Global Settings binding slot |
| `settings_visible=false` | Task/editor only (e.g. `article.content.generate`, `article.content.rewrite`) |

Hook does **not** activate a Prompt; Settings or Task reference does.

## 5. Read path

1. UI/Settings: `PromptHookEditorCatalog::settingsVisibleHooks()` → bindings via `SeoCreateArticleSettingsService::getBoundPromptId`.
2. Resolve Prompt: `SettingsPromptBindingResolver` / Task node `prompt_id` — **never** “find active prompt by hook”.
3. Hook execute: Registry + InputResolver + SettingsResolver → Assembler → `PromptRunnerService` → OutputNormalizer.
4. Provider: `AiProviderResolver::assertTextReady($providerId)` fail-closed (`ai_provider.not_configured|not_registered|disabled`).
5. Model Status / Overview: `AiModelRouterService::overviewForUser()` + `GeminiModelVersionPolicy::routingDecision()` (major ≥ 3 for auto-route).

Form encoding: Filament `.` nesting — form keys `article__title_suggestion` decode to `article.title_suggestion` on save.

## 6. Write path

```text
Settings save
  → SeoCreateArticleSettingsService
  → prompt_hook_bindings (SoT)
  → migrate-on-read legacy keys if present (read only)

Prompt Hook execute
  → PromptHookExecutionService | PromptHookCallerBridge (legacy|shadow|hook)
  → PromptRunnerService → AiProviderResolver → provider
  → optional PromptResult row
  → UI apply OR caller → Business Action / Workflow action
  → prompt_result.attach (domain) — not Hook Engine

Task / Content Project workflow
  → TaskWorkflowTestRunner prompt nodes → PromptRunnerService / ExplicitBinding
  → each successful prompt registers typed artifact (article_outline | article_content | …)
  → action nodes consume only declared typed dependencies (PromptTestPublishService)
  → WP outbound only via wordpress.* / Publishing module
```

**Artifact ownership (canonical):**

1. `PromptResult` row = audit — **not** automatically a domain artifact.
2. Every workflow prompt success has `artifact_type` + producer node identity.
3. Domain action consumes only declared typed dependencies (`article_content` for body write).
4. `article_outline` can **never** satisfy `article_content`.
5. Hook inputs are schema-whitelisted per current hook (`mapInput` allowed keys only).
6. No shared-payload leakage (e.g. `topic`) across hooks.
7. Failed prompt → its domain write action is blocked; no previous-node / latest-result fallback.
8. **Manual AI History** (`ArticleAiHistoryApplicationService`): users may list/preview/apply/delete PromptResult + typed artifact history per article. Only valid typed (or fail-closed legacy-classified) `article_outline` / `article_content` may apply — via domain/editor draft services, **never** Hook Engine. Legacy classification is fail-closed (`ArticleAiHistoryLegacyClassifier`). Deleting history tombstones audit rows and unlinks PromptResult; does **not** delete article body/outline, project task/run, or editor revisions. Shared PromptResult hard-clear only when orphaned.

**Modes (migration bridge):** `legacy` (default) | `shadow` (legacy SoT, no double AI) | `hook` (engine + provider once). Rollback any → `legacy`. Jump `legacy` → `hook` **forbidden** (must shadow first).

## 7. Public capabilities

- Settings-visible hook binding CRUD (manager).
- Prompt CRUD (planner); Test Prompt; Sync models on connection.
- Execute hook API for editor fields (title/meta/FAQ/… per catalog).
- Article Editor FAQ AI: `article.faq.generate` via `generatePreview` (no auto body write); Apply uses FAQ snapshot + owning editor session — see [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md).
- Explicit editor binding may run Hook Runtime for one Prompt without flipping global migration mode.
- API Connections: AI (`gemini`/`claude`) + SEO providers via unified list.
- Extension AI providers listed/health via Extensions UI.

## 8. Internal-only capabilities

| Item | Notes |
|------|-------|
| `PromptRunnerService` internals | compile / callProvider / chain steps |
| `AiModelRouterService` failover | marks unavailable; not Agent-facing |
| `prompt_result.attach` | Business Action / attach service after AI |
| Image post-processing | Quick split / resize / output-mode inject |
| `SeoProviderCapabilityResolver` | Matrix for list UI |
| Hook Spec validators / fixtures | Spec engine; production loader remains manifest schema |

## 9. Authorization and confirmation

- Settings: manager+.
- Prompt mutate: planner + panel mutation gate; tenant `user_id` scope via `getEloquentQuery`.
- Hook entity resolvers authorize article/site then return **array** context — no Eloquent to caller.
- Secrets: encrypted `api_key` on connections; **never** in hook JSON / events / logs.
- Delete Prompt blocked when Settings binding or Task Prompt Block references it; Hook change blocked while Settings still bound to old hook.

## 10. Queue and scheduler ownership

| Path | Queue / schedule |
|------|------------------|
| Media / image jobs | `media_generation` / `seo` as existing GenerateMediaJob wiring |
| SEO analyze after content | scoring jobs (Action may defer) |
| Prompt hooks themselves | Sync HTTP for editor execute; no dedicated hook cron |

No Prompt Hook schedule owner — workflows/CP/Agent own cadence.

## 11. Transactions and side effects

- Hook: build request, validate/normalize output — **no** Eloquent save Article/Keyword/Task; **no** WP sync; **no** Business Action call from Hook Engine.
- Domain write after AI = Workflow action **or** caller → Business Action **or** user save.
- `PromptResult` row = audit; attach to Article/Task/Project = `prompt_result.attach` only.
- Image compile may inject Runtime Image Output Mode block (not stored as Manual Hook template).
- Scoring rule settings save does **not** bulk rewrite `article_meta.seo_rule_violations`.

## 12. Retry and recovery

- Workflow owns multi-step retry; Hook may declare `retry.max` for empty/invalid **before** domain write.
- Model failover via `AiModelRouterService` (exhausted / provider unavailable).
- Mode `hook`: after provider cost — **no** silent legacy fallback.
- Invalid output: fail closed — no silent accept.

## 13. Compatibility paths

- Legacy option prompt_id fields: migrate-on-read into bindings; keep for rollback.
- `legacy_prompt_content` template source: SeoPrompt DB markdown SoT; Hook JSON = contract.
- Phase-1 `PromptHookExecutionService` may orchestrate attach via `PromptResultAttachService` (orchestrator role) — Runtime Engine path does not attach.
- Catalog alias `project.prompt_result.attach` → canonical `prompt_result.attach`.
- Gemini 2.x models may sync/display — excluded from auto-routing (major ≥ 3).

## 14. Forbidden paths

- **Dual-write SoT:** write/activate both legacy per-field prompt_id keys **and** `prompt_hook_bindings` as competing runtime sources; runtime reads bindings only.
- Resolve Prompt by “active/`is_active` for hook” (`is_active` legacy column not a runtime gate).
- Hard-code OpenAI/Gemini/Claude vendor inside PromptRunner — use `AiProviderResolver`.
- Secrets / `api_key` in hook JSON or templates.
- PHP/`eval` in templates; Filament/WP sync imports inside Hook Spec/Normalizer.
- Hook Engine → Eloquent domain save or WP HTTP.
- Silent legacy fallback after hook provider already charged.
- Jump migration `legacy` → `hook` without `shadow`.
- Recommendations page treated as runtime routing config.

## 15. Tests and invariants

| Area | Tests / evidence |
|------|------------------|
| Prompt ownership / bindings | Prompt ownership model tests; `seo:workflow:doctor` labels |
| Hook boundaries | Hook Spec / Runtime unit suites under `PromptHooks` |
| Extension AI resolve | `ExtensionArchitectureFreezeTest`, `ExtensionSdkFoundationTest` |
| RuntimeLogger (HTTP AI controllers) | `RuntimeLoggerWebAppChannelTest` |

**Invariants:** bindings SoT; Task-owned prompts separate; Hook ≠ domain write; provider via resolver; no dual-write legacy+bindings; fail-closed provider/output.

## 16. Related documents

- `docs/modules/EXTENSION_SDK.md`
- `docs/contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md`
- `docs/modules/AUTOMATION.md`
- `docs/modules/CONTENT_PROJECTS.md`
- `docs/modules/PUBLISHING.md`
- `docs/modules/OPERATIONS_AND_OBSERVABILITY.md`
- `docs/operations/TESTING.md`
)
