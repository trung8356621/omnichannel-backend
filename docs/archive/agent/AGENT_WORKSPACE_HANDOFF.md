> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
<!--
Status: Historical
Not canonical
Superseded by: docs/AGENT_WORKSPACE.md + docs/AGENT_WORKSPACE_V1_FREEZE.md
-->
# Agent Workspace UI Integration Handoff

## Root cause â€” UI â€œchÆ°a tháº¥yâ€ / chÆ°a dÃ¹ng Ä‘Æ°á»£c

KhÃ´ng pháº£i thiáº¿u class `AgentWorkspacePage` hay thiáº¿u discover Filament (page náº±m Ä‘Ãºng `Filament/Pages`, SEO panel `discoverPages`).

NguyÃªn nhÃ¢n thá»±c táº¿ (UX + wiring):

1. **Popup AI star váº«n lÃ  in-popup AI runtime** (`switchTab('ai')` + `loadModels` + `send()` AI API). User tÆ°á»Ÿng ngÃ´i sao = Agent Workspace nhÆ°ng váº«n káº¹t trong Quick Assistant cÅ©.
2. **Deep link yáº¿u**: `AgentWorkspaceDeepLink::url()` catch throwable rá»“i fallback `url('/seo')` â€” thiáº¿u `connection_hash` khÃ´ng Ä‘Æ°a vÃ o `/agent`, dá»… cáº£m giÃ¡c â€œkhÃ´ng má»Ÿ Ä‘Æ°á»£c Agentâ€.
3. **Agent page visual lá»‡ch popup**: layout dashboard 3 cá»™t generic, khÃ´ng reuse visual chat cÅ© â†’ khÃ³ nháº­n ra Ä‘Ã¢y lÃ  Agent Workspace â€œÄ‘Ã£ má»Ÿ rá»™ngâ€.
4. NÃºt text â€œOpen Agent Workspaceâ€ thÃªm trÆ°á»›c Ä‘Ã³ dá»… bá» qua; khÃ´ng thay hÃ nh vi tab ngÃ´i sao.

KhÃ´ng pháº£i lá»—i CSS che registration. KhÃ´ng pháº£i thiáº¿u route slug `agent`.

## Patch Ä‘Ã£ lÃ m (UI Integration â€” khÃ´ng Phase 2)

### Popup (`global-ai-chat`)
- Team tab = Quick Assistant (giá»¯ nguyÃªn runtime team).
- Tab/ngÃ´i sao AI = **launcher** `openAgentWorkspace()` â†’ `window.location.assign(url)`.
- KhÃ´ng set `activeTab = 'ai'`.
- KhÃ´ng `loadModels()` / khÃ´ng gá»i AI API khi báº¥m ngÃ´i sao.
- KhÃ´ng táº¡o Agent conversation / khÃ´ng CommandBus tá»« popup.
- `@ai` trong Team composer cÅ©ng launch Agent Workspace.

### Deep link
- `AgentWorkspaceDeepLink::tryUrl()` / `forCurrentRequest()`.
- Resolve `connection_hash` tá»« context/request/session â€” **khÃ´ng** random connection.
- Infer `project_ref` tá»« path `content-projects/{id}` hoáº·c global content project picker.
- Params: `project_ref`, `workspace_ref`, `article_ref`, `operation_ref`, `conversation`, `skill`, `template`.
- Missing hash â†’ message: â€œVui lÃ²ng chá»n website trÆ°á»›c khi má»Ÿ Agent Workspace.â€

### Shared presentation
`resources/views/components/seo-agent-chat/`:
- star-icon, header, empty-state, message, composer, disclaimer  
Popup + Agent page dÃ¹ng chung presentation; runtime tÃ¡ch.

### Agent page
- Main chat reuse class/`seo-global-chat` visual + `agent-workspace.css`.
- Conversations | Chat-first | Context.
- Templates empty state, slash palette, skill form.
- Deep link `skill`/`template`/`conversation` chá»‰ prefill â€” **khÃ´ng auto execute**.

### Admin alias
`/admin/agent` dÃ¹ng `tryUrl()`; thiáº¿u connection â†’ message chá»n website, khÃ´ng random.

## UI Interaction Fix

### Root cause â€” template/skill click khÃ´ng hoáº¡t Ä‘á»™ng

1. **Recommended Skills** dÃ¹ng `@disabled(!usable)` â†’ click bá»‹ HTML cháº·n hoÃ n toÃ n; user khÃ´ng tháº¥y reason/form unavailable.
2. Template cards dÃ¹ng `wire:target="openTemplate('key')"` (method + argument) â€” Livewire loading target dá»… lá»‡ch / lÃ m click trÃ´ng nhÆ° â€œkhÃ´ng cháº¡yâ€.
3. Template grid náº±m trong Blade component slot `empty-state` â€” rá»§i ro scope Livewire; Ä‘Ã£ kÃ©o ra ngoÃ i slot.
4. Formless skill (`/daily-report`, `/site-health`) **xoÃ¡ composer** thay vÃ¬ prefill â†’ cáº£m giÃ¡c â€œbáº¥m khÃ´ng cÃ³ tÃ¡c dá»¥ngâ€.
5. Invalid template/skill tráº£ vá» silent (`return`) khÃ´ng notification.

### Shared interaction path

Browser chá»‰ gá»­i **key** (`Js::from`). Server resolve láº¡i tá»« registry.

- `selectTemplate($templateKey)` â†’ `AgentChatTemplateRegistry` â†’ set `activeTemplateKey` â†’ `selectSkill(skillKey)` (hoáº·c prefill composer náº¿u template khÃ´ng gáº¯n skill).
- `selectSkill($skillKey, $prefill = [])` â†’ `AgentSkillRegistry` (key hoáº·c slash) â†’ `AgentWorkspaceApplicationService::openSkill` â†’ set form/meta/availability. **KhÃ´ng** gá»i Gateway/CommandBus/execute.
- Slash palette click + Enter (`selectPalette`) cÃ¹ng gá»i `selectSkill`.
- `openSkill` / `openTemplate` giá»¯ alias deprecated â†’ `select*`.

Ghi rÃµ:

- Template click does not execute.
- Skill click does not execute.
- Execution starts only after submit/confirmation.
- Global chat is not mounted inside Agent Workspace.
- Global chat remains available elsewhere.

### Skill form / composer

- CÃ³ `form_schema` â†’ render skill form (header, mÃ´ táº£, availability badge, fields, preview/confirm theo policy). Unavailable â†’ **khÃ´ng** hiá»‡n nÃºt execute.
- KhÃ´ng form â†’ prefill composer báº±ng slash náº¿u composer rá»—ng + focus; váº«n set active skill cho context panel.
- Cancel â†’ `clearSkillSelection()` â€” vá» empty state, **khÃ´ng** xoÃ¡ conversation.
- Click card/skill **khÃ´ng** táº¡o execution.

### Global chat suppression

- Root cause floating chat: `SeoPanelProvider` BODY_END mount `global-ai-chat` trÃªn má»i `seo/*` (trá»« article edit / keywords) â€” **khÃ´ng** loáº¡i trá»« Agent page.
- Fix: `AgentWorkspaceUiContext::hidesGlobalChat()` â€” route `filament.seo.pages.agent`, `filament.admin.pages.agent`, fallback path `seo/{hash}/agent`, `admin/agent`.
- Khi hide: **khÃ´ng render** launcher, **khÃ´ng mount** Alpine/runtime, khÃ´ng `loadModels`, khÃ´ng keyboard shortcut global chat. Váº«n render `workspace-media-picker`.
- Trang khÃ¡c (Content Project, Keywordâ€¦) váº«n cÃ³ floating chat; ngÃ´i sao váº«n deep-link Agent Workspace.

### Freeze boundary

- Gateway / CommandBus / Skill Registry business modules / Freeze handlers: **khÃ´ng sá»­a**.
- Chá»‰ UI page, blades, UiContext, panel hook, lang, tests, handoff.

### Tests Ä‘Ã£ bá»• sung (source-level)

`AgentWorkspaceUiTest`: selectSkill/selectTemplate khÃ´ng execute; recommended khÃ´ng `@disabled`; skill form áº©n execute khi unavailable; global chat suppress wiring; Js::from + select* trong blade.

## Manual verification

```text
Manual verification:

php artisan optimize:clear
npm run build

$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceUi
$PHP_BIN vendor/bin/phpunit --filter=AgentSkill
$PHP_BIN vendor/bin/phpunit --filter=AgentChat
$PHP_BIN vendor/bin/phpunit --filter=AgentDeepLink
$PHP_BIN vendor/bin/phpunit --filter=ExtensionArchitectureFreezeTest
```

Browser:
1. Má»Ÿ `/seo/{hash}/agent` â€” **khÃ´ng** cÃ²n nÃºt chat ná»•i gÃ³c pháº£i.
2. Click â€œTáº¡o project má»›iâ€ â†’ form `/create-project` (khÃ´ng execution).
3. Cancel â†’ empty state.
4. Click Recommended `/daily-report` â†’ form hoáº·c composer prefill.
5. GÃµ `/` â†’ palette â†’ Enter/click dÃ¹ng `selectSkill`.
6. Sang Content Project â€” floating chat hiá»‡n láº¡i; Team OK; ngÃ´i sao â†’ Agent Workspace.
