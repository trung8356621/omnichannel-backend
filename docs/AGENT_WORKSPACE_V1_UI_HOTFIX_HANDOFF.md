# Agent Workspace v1.0 UI Hotfix Handoff (FINAL — interaction parser)

P0 STOP. Không Phase 8. Freeze CommandBus / Gateway / Orchestrators / Phase 4–7.

## 1. Malformed expression source

**Symptom:** `Alpine Expression Error: Unexpected token ')'` trên expression dạng `$wire.selectTemplate(...)`.

**Root cause:** Dynamic Blade value nhúng vào JS expression string qua 4 lớp parse:

Blade render → HTML parser → Livewire expression parser → Alpine evaluator

Các pattern cũ (đã loại):

```html
wire:click="selectTemplate('{{ $key }}')"
wire:click='selectTemplate(@js($key))'
x-on:click="$wire.selectTemplate(@js($key))"
x-bind:class="{ 'is-active': {{ $idx }} === paletteIndex }"
```

Key có dấu `.` / `-` / nháy / Unicode → expression vỡ dù vá quote từng chỗ.

**Files tạo lỗi trước đây:**

- `agent-workspace.blade.php` — template cards, palette items
- `agent-context-panel.blade.php` — quick commands
- Message/panel partials dùng `wire:click` + `@js` / `Js::from`

## 2. Rendered HTML trước sửa (ví dụ)

```html
<button type="button" wire:click='selectTemplate(@js($card["key"]))' ...>
```

Sau Blade (key = `keyword-opportunities`) dễ thành expression Livewire/Alpine malformed khi quote lệch:

```html
<button ... wire:click="selectTemplate("keyword-opportunities")">
<!-- hoặc -->
<button ... x-on:click="$wire.selectTemplate('keyword-opportunities')">
```

Dynamic key **nằm trong** expression string.

## 3. Rendered HTML sau sửa (bắt buộc)

```html
<button
    type="button"
    value="keyword-opportunities"
    x-on:click="$wire.selectTemplate($el.value)"
    class="seo-agent-workspace__template-card"
>
```

Palette:

```html
<button
    type="button"
    value="content_project.create"
    data-index="0"
    x-bind:class="paletteIndex === Number($el.dataset.index) && 'is-active'"
    x-on:click="selectPaletteElement($el)"
>
```

Expression **static**. Key chỉ ở HTML attribute.

## 4. Dynamic inline expressions đã loại

Ước lượng audit Agent Workspace blades:

| Loại | ~Số đã loại |
|------|-------------|
| `wire:click` + `@js` / `'{{` dynamic arg | ~25+ |
| `x-on:click` + `@js` / Blade string arg | ~15+ |
| `x-bind:class="{ ... }"` object literal | ~3 |
| `@click` shorthand | ~5 |
| Dual `wire:click` + Alpine click cùng element | ~10+ |

**Shared component:** `resources/views/components/agent-workspace/action-button.blade.php`

- Allowlist `action` prop
- Literal static `x-on:click="$wire.<method>($el.value)"` (không `{{ $expression }}`)
- Key chỉ trong `value="{{ $reference }}"` (+ `data-decision` cho memory proposal)

## 5. Interaction owners

| Surface | Owner | Binding |
|---------|-------|---------|
| Skill / recommended / suggested cards | Alpine → Livewire | `x-on:click="$wire.selectSkill($el.value)"` hoặc `selectCommand` |
| Template cards | Alpine → Livewire | `x-on:click="$wire.selectTemplate($el.value)"` |
| Quick commands | Alpine → Livewire | `action="selectCommand"` → `$wire.selectCommand($el.value)` |
| Palette click / keyboard | Alpine `selectPaletteElement` / `selectPalette` | rồi `$wire.selectCommand(command)` |
| Composer | Alpine **duy nhất** `submitAgentComposer()` | form `x-on:submit.prevent`; Enter (no Shift, palette đóng) → submit; palette mở → select only; send = `type="submit"`; **không** `wire:click` / `wire:submit` |
| Panel tabs (chat/knowledge/…) | Livewire static | `wire:click="openChatPanel"` (không dynamic arg) |
| Confirm-gated (forget/delete pack) | Alpine static | `confirm(...) && $wire.method($el.value)` + `value="..."` |

## 6. Server validation

`AgentWorkspacePage`:

- `normalizeAgentReference()` — trim, max length 190, reject control chars
- `selectSkill` — registry resolve + availability; **ignore** browser prefill array
- `selectTemplate` — `AgentChatTemplateRegistry::get` rồi `selectSkill`
- `selectCommand` → `selectSkill`
- `sendMessage(?string $message = null)` — nhận text từ Alpine; không tin metadata capability từ browser

## 7. Console result

Agent remote-first — **không** chạy browser trong session. Sau deploy expect:

- Không `Alpine Expression Error`
- Không `Unexpected token`
- Không Livewire method not found / snapshot missing từ click template

## 8. Network result

Expect sau hard refresh:

- Click template / skill / quick / palette → Livewire XHR/fetch, **không** document navigation
- Composer Enter → một `sendMessage` request, HTTP 200
- Shift+Enter → không request

## 9. Tests

```text
$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceInteractionBindingTest
$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceUiHotfixTest
$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceUiTest
```

`AgentWorkspaceInteractionBindingTest`:

- action-button static `$el.value`
- scan blades: no `@js` / `Js::from` / dynamic `wire:click` / object `x-bind:class` / `@click`
- DOMDocument simulate keys: dots, hyphen, `keyword-opportunities`, `content_project.create`, quotes
- composer single submit owner
- page `selectCommand` + `normalizeAgentReference` + `sendMessage(?string)`

## 10. Cache / build

```text
Manual verification:

php artisan view:clear
php artisan optimize:clear
npm run build

# nếu host có livewire:discover:
php artisan livewire:discover

$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceInteractionBindingTest
$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceUiHotfixTest
```

Xác nhận không còn expression cũ trong `storage/framework/views` (Blade cache). Không sửa `vendor/livewire`.

DevTools Elements — template card phải thấy:

```text
value="…"
x-on:click="$wire.selectTemplate($el.value)"
```

Không được thấy `@js`, `Js::from`, `$wire.selectTemplate("`, `$wire.selectTemplate('` trong attribute expression.

## 11. Freeze verification

Không sửa:

- CommandBus, handlers, AgentGateway
- ExecutionOrchestrator, PlanningOrchestrator
- Capability Registry / Phase 4–7 services

Chỉ interaction/render boundary: page methods normalize/send args, blades, `action-button`, composer Alpine, CSS (trước đó), unit contract tests, doc này.

## 12. STOP

Hotfix interaction parser **xong**. Không tiếp tục Phase 8 / quote patching.
