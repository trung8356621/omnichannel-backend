# Agent Workspace UI Integration Handoff

## Root cause — UI “chưa thấy” / chưa dùng được

Không phải thiếu class `AgentWorkspacePage` hay thiếu discover Filament (page nằm đúng `Filament/Pages`, SEO panel `discoverPages`).

Nguyên nhân thực tế (UX + wiring):

1. **Popup AI star vẫn là in-popup AI runtime** (`switchTab('ai')` + `loadModels` + `send()` AI API). User tưởng ngôi sao = Agent Workspace nhưng vẫn kẹt trong Quick Assistant cũ.
2. **Deep link yếu**: `AgentWorkspaceDeepLink::url()` catch throwable rồi fallback `url('/seo')` — thiếu `connection_hash` không đưa vào `/agent`, dễ cảm giác “không mở được Agent”.
3. **Agent page visual lệch popup**: layout dashboard 3 cột generic, không reuse visual chat cũ → khó nhận ra đây là Agent Workspace “đã mở rộng”.
4. Nút text “Open Agent Workspace” thêm trước đó dễ bỏ qua; không thay hành vi tab ngôi sao.

Không phải lỗi CSS che registration. Không phải thiếu route slug `agent`.

## Patch đã làm (UI Integration — không Phase 2)

### Popup (`global-ai-chat`)
- Team tab = Quick Assistant (giữ nguyên runtime team).
- Tab/ngôi sao AI = **launcher** `openAgentWorkspace()` → `window.location.assign(url)`.
- Không set `activeTab = 'ai'`.
- Không `loadModels()` / không gọi AI API khi bấm ngôi sao.
- Không tạo Agent conversation / không CommandBus từ popup.
- `@ai` trong Team composer cũng launch Agent Workspace.

### Deep link
- `AgentWorkspaceDeepLink::tryUrl()` / `forCurrentRequest()`.
- Resolve `connection_hash` từ context/request/session — **không** random connection.
- Infer `project_ref` từ path `content-projects/{id}` hoặc global content project picker.
- Params: `project_ref`, `workspace_ref`, `article_ref`, `operation_ref`, `conversation`, `skill`, `template`.
- Missing hash → message: “Vui lòng chọn website trước khi mở Agent Workspace.”

### Shared presentation
`resources/views/components/seo-agent-chat/`:
- star-icon, header, empty-state, message, composer, disclaimer  
Popup + Agent page dùng chung presentation; runtime tách.

### Agent page
- Main chat reuse class/`seo-global-chat` visual + `agent-workspace.css`.
- Conversations | Chat-first | Context.
- Templates empty state, slash palette, skill form.
- Deep link `skill`/`template`/`conversation` chỉ prefill — **không auto execute**.

### Admin alias
`/admin/agent` dùng `tryUrl()`; thiếu connection → message chọn website, không random.

## UI Interaction Fix

### Root cause — template/skill click không hoạt động

1. **Recommended Skills** dùng `@disabled(!usable)` → click bị HTML chặn hoàn toàn; user không thấy reason/form unavailable.
2. Template cards dùng `wire:target="openTemplate('key')"` (method + argument) — Livewire loading target dễ lệch / làm click trông như “không chạy”.
3. Template grid nằm trong Blade component slot `empty-state` — rủi ro scope Livewire; đã kéo ra ngoài slot.
4. Formless skill (`/daily-report`, `/site-health`) **xoá composer** thay vì prefill → cảm giác “bấm không có tác dụng”.
5. Invalid template/skill trả về silent (`return`) không notification.

### Shared interaction path

Browser chỉ gửi **key** (`Js::from`). Server resolve lại từ registry.

- `selectTemplate($templateKey)` → `AgentChatTemplateRegistry` → set `activeTemplateKey` → `selectSkill(skillKey)` (hoặc prefill composer nếu template không gắn skill).
- `selectSkill($skillKey, $prefill = [])` → `AgentSkillRegistry` (key hoặc slash) → `AgentWorkspaceApplicationService::openSkill` → set form/meta/availability. **Không** gọi Gateway/CommandBus/execute.
- Slash palette click + Enter (`selectPalette`) cùng gọi `selectSkill`.
- `openSkill` / `openTemplate` giữ alias deprecated → `select*`.

Ghi rõ:

- Template click does not execute.
- Skill click does not execute.
- Execution starts only after submit/confirmation.
- Global chat is not mounted inside Agent Workspace.
- Global chat remains available elsewhere.

### Skill form / composer

- Có `form_schema` → render skill form (header, mô tả, availability badge, fields, preview/confirm theo policy). Unavailable → **không** hiện nút execute.
- Không form → prefill composer bằng slash nếu composer rỗng + focus; vẫn set active skill cho context panel.
- Cancel → `clearSkillSelection()` — về empty state, **không** xoá conversation.
- Click card/skill **không** tạo execution.

### Global chat suppression

- Root cause floating chat: `SeoPanelProvider` BODY_END mount `global-ai-chat` trên mọi `seo/*` (trừ article edit / keywords) — **không** loại trừ Agent page.
- Fix: `AgentWorkspaceUiContext::hidesGlobalChat()` — route `filament.seo.pages.agent`, `filament.admin.pages.agent`, fallback path `seo/{hash}/agent`, `admin/agent`.
- Khi hide: **không render** launcher, **không mount** Alpine/runtime, không `loadModels`, không keyboard shortcut global chat. Vẫn render `workspace-media-picker`.
- Trang khác (Content Project, Keyword…) vẫn có floating chat; ngôi sao vẫn deep-link Agent Workspace.

### Freeze boundary

- Gateway / CommandBus / Skill Registry business modules / Freeze handlers: **không sửa**.
- Chỉ UI page, blades, UiContext, panel hook, lang, tests, handoff.

### Tests đã bổ sung (source-level)

`AgentWorkspaceUiTest`: selectSkill/selectTemplate không execute; recommended không `@disabled`; skill form ẩn execute khi unavailable; global chat suppress wiring; Js::from + select* trong blade.

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
1. Mở `/seo/{hash}/agent` — **không** còn nút chat nổi góc phải.
2. Click “Tạo project mới” → form `/create-project` (không execution).
3. Cancel → empty state.
4. Click Recommended `/daily-report` → form hoặc composer prefill.
5. Gõ `/` → palette → Enter/click dùng `selectSkill`.
6. Sang Content Project — floating chat hiện lại; Team OK; ngôi sao → Agent Workspace.
