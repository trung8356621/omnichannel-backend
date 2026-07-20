# Automation Module SDK

**Version:** 2026-07-20  
**Scope:** Platform hóa registry — **không** đổi execution engine, queue, graph, DB schema.

Core automation (`Automation/Platform/`, `Automation/BusinessHook/` engine) **không** chứa domain WordPress, Facebook, AI, Content Project, SEO. Domain đăng ký qua **module providers**.

---

## Folder structure

```text
app/Addons/SeoContentAi/
  config/
    automation-modules.php          # enable/disable modules
  Automation/
    Platform/
      Contracts/
        AutomationModuleProvider.php
      AutomationModuleContext.php
      AutomationModuleRegistry.php
      AutomationPlatformKernel.php
      Data/                         # DTO đăng ký
      Registry/                     # condition, health, menu, permission, settings
    Modules/
      Core/                         # delay, webhook, notification, dispatch_event
      WordPress/
      Content/
      Seo/
      Media/
      Sample/                       # ví dụ SDK (disabled mặc định)
    BusinessHook/                   # execution engine (không hardcode action list)
```

---

## ServiceProvider / boot

1. `SeoContentAiServiceProvider` merge `config/automation-modules.php`.
2. `AutomationPlatformKernel::bootOnce()` tạo `AutomationModuleContext` và gọi `AutomationModuleRegistry::boot()`.
3. Mỗi module implement `AutomationModuleProvider` — **một** method `register(AutomationModuleContext $context)`.

Disable module: set `false` trong config — core vẫn chạy, registry domain không có entry đó.

`AutomationModuleRegistry::fromConfig()` **load file** `config/automation-modules.php` trực tiếp (builtin fallback). Không phụ thuộc `mergeConfigFrom` — tránh registry trống khi `php artisan config:cache`.

```php
// config/automation-modules.php
\My\ModuleProvider::class => false,
```

---

## Event registration

```php
$context->events->register(new BusinessEventDefinition(
    name: 'my_module.thing_done',
    subject: MyModel::class,          // nullable
    payloadSchema: [
        'thing_id' => ['type' => 'integer', 'required' => true],
    ],
    description: 'Human label',
    module: 'my_module',
));
```

Engine chỉ biết `BusinessEventRegistry` — không import model domain trong core.

---

## Action registration

```php
$context->actions->register(new AutomationActionDefinition(
    actionCode: 'my_module.do_thing',   // string, không lưu PHP class trong DB
    handlerClass: MyThingHookAction::class,
    inputRules: ['thing_id' => ['type' => 'integer', 'required' => true]],
    settingsRules: [],
    description: '...',
    isAsyncSafe: true,
    timeout: 60,
    module: 'my_module',
    defaultQueue: AutomationQueueName::External->value,
));
```

Handler implement `AutomationActionHandler`.

---

## Condition registration

Built-in operators: `equals`, `contains`, … (core engine).

Module thêm operator:

```php
$context->conditions->registerOperator(new ConditionOperatorDefinition(
    name: 'my_starts_with',
    evaluator: fn ($actual, $expected, $clause, $sources) => ...,
    description: '...',
    module: 'my_module',
));

$context->conditions->registerFieldRoots('my_module', ['my_module']);
```

`AutomationConditionEngine` resolve custom operator qua `AutomationConditionRegistry`.

---

## Settings registration

```php
$context->settings->register(new SettingDefinition(
    key: 'my_module.api_url',
    module: 'my_module',
    label: 'API URL',
    schema: ['type' => 'string'],
    default: null,
));
```

Registry metadata — persistence theo convention module (không thêm bảng core trong phase SDK).

---

## Health registration

```php
$context->healthChecks->register(new HealthCheckDefinition(
    key: 'my_module.reachable',
    module: 'my_module',
    checker: fn (): array => ['status' => 'ok'],
    description: '...',
));
```

`AutomationHealthService::report()` merge `modules` từ registry.

---

## Menu & permissions (metadata)

```php
$context->menus->register(new MenuItemDefinition(...));
$context->permissions->register(new PermissionDefinition(...));
```

Filament/UI đọc registry sau — phase SDK chỉ khai báo, không đổi UI.

---

## Migration convention

- **Core** automation migrations: `Automation/BusinessHook` schema (`business_events`, `automation_*`).
- **Module** migrations: `Automation/Modules/{Name}/database/migrations/` (nếu cần sau).
- Không FK cross-module trong core.
- Module disabled → không boot registry; DB rows cũ của rule/action code vẫn tồn tại nhưng diagnose báo `UNREGISTERED_*` nếu rule enabled.

---

## Sample module

`Automation/Modules/Sample/SampleAutomationModuleProvider.php` — disabled trong config.

Bật test:

```php
SampleAutomationModuleProvider::class => true,
```

Đăng ký đủ: event `sample.ping`, action `sample.echo`, operator `sample_starts_with`, health, menu, permission, setting.

---

## Thêm module mới (checklist)

1. Tạo `{Name}AutomationModuleProvider` implement `AutomationModuleProvider`.
2. Đăng ký events/actions/conditions trong `register()`.
3. Thêm class vào `config/automation-modules.php` (`true`/`false`).
4. Handler classes trong module folder — không sửa `AutomationGraphExecutionService` / jobs.
5. Unit test registry (xem `AutomationModuleSdkTest`).

---

## Invariants (không đổi)

- Execution engine, queue, graph, versioning semantics giữ nguyên.
- DB rule lưu `action_code` string — resolve handler qua registry lúc runtime.
- Draft không execute; published version immutable.
