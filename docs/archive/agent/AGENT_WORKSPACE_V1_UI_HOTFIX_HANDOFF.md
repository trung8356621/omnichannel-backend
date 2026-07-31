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
# Agent Workspace v1.0 UI Hotfix Handoff (FINAL â€” interaction parser)

P0 STOP. KhÃ´ng Phase 8. Freeze CommandBus / Gateway / Orchestrators / Phase 4â€“7.

## 1. Malformed expression source

**Symptom:** `Alpine Expression Error: Unexpected token ')'` trÃªn expression dáº¡ng `$wire.selectTemplate(...)`.

**Root cause:** Dynamic Blade value nhÃºng vÃ o JS expression string qua 4 lá»›p parse:

Blade render â†’ HTML parser â†’ Livewire expression parser â†’ Alpine evaluator

CÃ¡c pattern cÅ© (Ä‘Ã£ loáº¡i):

```html
wire:click="selectTemplate('{{ $key }}')"
wire:click='selectTemplate(@js($key))'
x-on:click="$wire.selectTemplate(@js($key))"
x-bind:class="{ 'is-active': {{ $idx }} === paletteIndex }"
```

Key cÃ³ dáº¥u `.` / `-` / nhÃ¡y / Unicode â†’ expression vá»¡ dÃ¹ vÃ¡ quote tá»«ng chá»—.

**Files táº¡o lá»—i trÆ°á»›c Ä‘Ã¢y:**

- `agent-workspace.blade.php` â€” template cards, palette items
- `agent-context-panel.blade.php` â€” quick commands
- Message/panel partials dÃ¹ng `wire:click` + `@js` / `Js::from`

## 2. Rendered HTML trÆ°á»›c sá»­a (vÃ­ dá»¥)

```html
<button type="button" wire:click='selectTemplate(@js($card["key"]))' ...>
```

Sau Blade (key = `keyword-opportunities`) dá»… thÃ nh expression Livewire/Alpine malformed khi quote lá»‡ch:

```html
<button ... wire:click="selectTemplate("keyword-opportunities")">
<!-- hoáº·c -->
<button ... x-on:click="$wire.selectTemplate('keyword-opportunities')">
```

Dynamic key **náº±m trong** expression string.

## 3. Rendered HTML sau sá»­a (báº¯t buá»™c)

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

Expression **static**. Key chá»‰ á»Ÿ HTML attribute.

## 4. Dynamic inline expressions Ä‘Ã£ loáº¡i

Æ¯á»›c lÆ°á»£ng audit Agent Workspace blades:

| Loáº¡i | ~Sá»‘ Ä‘Ã£ loáº¡i |
|------|-------------|
| `wire:click` + `@js` / `'{{` dynamic arg | ~25+ |
| `x-on:click` + `@js` / Blade string arg | ~15+ |
| `x-bind:class="{ ... }"` object literal | ~3 |
| `@click` shorthand | ~5 |
| Dual `wire:click` + Alpine click cÃ¹ng element | ~10+ |

**Shared component:** `resources/views/components/agent-workspace/action-button.blade.php`

- Allowlist `action` prop
- Literal static `x-on:click="$wire.<method>($el.value)"` (khÃ´ng `{{ $expression }}`)
- Key chá»‰ trong `value="{{ $reference }}"` (+ `data-decision` cho memory proposal)

## 5. Interaction owners

| Surface | Owner | Binding |
|---------|-------|---------|
| Skill / recommended / suggested cards | Alpine â†’ Livewire | `x-on:click="$wire.selectSkill($el.value)"` hoáº·c `selectCommand` |
| Template cards | Alpine â†’ Livewire | `x-on:click="$wire.selectTemplate($el.value)"` |
| Quick commands | Alpine â†’ Livewire | `action="selectCommand"` â†’ `$wire.selectCommand($el.value)` |
| Palette click / keyboard | Alpine `selectPaletteElement` / `selectPalette` | rá»“i `$wire.selectCommand(command)` |
| Composer | Alpine **duy nháº¥t** `submitAgentComposer()` | form `x-on:submit.prevent`; Enter (no Shift, palette Ä‘Ã³ng) â†’ submit; palette má»Ÿ â†’ select only; send = `type="submit"`; **khÃ´ng** `wire:click` / `wire:submit` |
| Panel tabs (chat/knowledge/â€¦) | Livewire static | `wire:click="openChatPanel"` (khÃ´ng dynamic arg) |
| Confirm-gated (forget/delete pack) | Alpine static | `confirm(...) && $wire.method($el.value)` + `value="..."` |

## 6. Server validation

`AgentWorkspacePage`:

- `normalizeAgentReference()` â€” trim, max length 190, reject control chars
- `selectSkill` â€” registry resolve + availability; **ignore** browser prefill array
- `selectTemplate` â€” `AgentChatTemplateRegistry::get` rá»“i `selectSkill`
- `selectCommand` â†’ `selectSkill`
- `sendMessage(?string $message = null)` â€” nháº­n text tá»« Alpine; khÃ´ng tin metadata capability tá»« browser

## 7. Console result

Agent remote-first â€” **khÃ´ng** cháº¡y browser trong session. Sau deploy expect:

- KhÃ´ng `Alpine Expression Error`
- KhÃ´ng `Unexpected token`
- KhÃ´ng Livewire method not found / snapshot missing tá»« click template

## 8. Network result

Expect sau hard refresh:

- Click template / skill / quick / palette â†’ Livewire XHR/fetch, **khÃ´ng** document navigation
- Composer Enter â†’ má»™t `sendMessage` request, HTTP 200
- Shift+Enter â†’ khÃ´ng request

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

# náº¿u host cÃ³ livewire:discover:
php artisan livewire:discover

$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceInteractionBindingTest
$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspaceUiHotfixTest
```

XÃ¡c nháº­n khÃ´ng cÃ²n expression cÅ© trong `storage/framework/views` (Blade cache). KhÃ´ng sá»­a `vendor/livewire`.

DevTools Elements â€” template card pháº£i tháº¥y:

```text
value="â€¦"
x-on:click="$wire.selectTemplate($el.value)"
```

KhÃ´ng Ä‘Æ°á»£c tháº¥y `@js`, `Js::from`, `$wire.selectTemplate("`, `$wire.selectTemplate('` trong attribute expression.

## 11. Freeze verification

KhÃ´ng sá»­a:

- CommandBus, handlers, AgentGateway
- ExecutionOrchestrator, PlanningOrchestrator
- Capability Registry / Phase 4â€“7 services

Chá»‰ interaction/render boundary: page methods normalize/send args, blades, `action-button`, composer Alpine, CSS (trÆ°á»›c Ä‘Ã³), unit contract tests, doc nÃ y.

## 12. STOP

Hotfix interaction parser **xong**. KhÃ´ng tiáº¿p tá»¥c Phase 8 / quote patching.
