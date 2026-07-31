> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Module SDK

**Version:** 2026-07-20  
**Scope:** Platform hÃ³a registry â€” **khÃ´ng** Ä‘á»•i execution engine, queue, graph, DB schema.

Core automation (`Automation/Platform/`, `Automation/BusinessHook/` engine) **khÃ´ng** chá»©a domain WordPress, Facebook, AI, Content Project, SEO. Domain Ä‘Äƒng kÃ½ qua **module providers**.

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
      Data/                         # DTO Ä‘Äƒng kÃ½
      Registry/                     # condition, health, menu, permission, settings
    Modules/
      Core/                         # delay, webhook, notification, dispatch_event
      WordPress/
      Content/
      Seo/
      Media/
      Sample/                       # vÃ­ dá»¥ SDK (disabled máº·c Ä‘á»‹nh)
    BusinessHook/                   # execution engine (khÃ´ng hardcode action list)
```

---

## ServiceProvider / boot

1. `SeoContentAiServiceProvider` merge `config/automation-modules.php`.
2. `AutomationPlatformKernel::bootOnce()` táº¡o `AutomationModuleContext` vÃ  gá»i `AutomationModuleRegistry::boot()`.
3. Má»—i module implement `AutomationModuleProvider` â€” **má»™t** method `register(AutomationModuleContext $context)`.

Disable module: set `false` trong config â€” core váº«n cháº¡y, registry domain khÃ´ng cÃ³ entry Ä‘Ã³.

`AutomationModuleRegistry::fromConfig()` **load file** `config/automation-modules.php` trá»±c tiáº¿p (builtin fallback). KhÃ´ng phá»¥ thuá»™c `mergeConfigFrom` â€” trÃ¡nh registry trá»‘ng khi `php artisan config:cache`.

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

Engine chá»‰ biáº¿t `BusinessEventRegistry` â€” khÃ´ng import model domain trong core.

---

## Action registration

```php
$context->actions->register(new AutomationActionDefinition(
    actionCode: 'my_module.do_thing',   // string, khÃ´ng lÆ°u PHP class trong DB
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

Built-in operators: `equals`, `contains`, â€¦ (core engine).

Module thÃªm operator:

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

Registry metadata â€” persistence theo convention module (khÃ´ng thÃªm báº£ng core trong phase SDK).

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

`AutomationHealthService::report()` merge `modules` tá»« registry.

---

## Menu & permissions (metadata)

```php
$context->menus->register(new MenuItemDefinition(...));
$context->permissions->register(new PermissionDefinition(...));
```

Filament/UI Ä‘á»c registry sau â€” phase SDK chá»‰ khai bÃ¡o, khÃ´ng Ä‘á»•i UI.

---

## Migration convention

- **Core** automation migrations: `Automation/BusinessHook` schema (`business_events`, `automation_*`).
- **Module** migrations: `Automation/Modules/{Name}/database/migrations/` (náº¿u cáº§n sau).
- KhÃ´ng FK cross-module trong core.
- Module disabled â†’ khÃ´ng boot registry; DB rows cÅ© cá»§a rule/action code váº«n tá»“n táº¡i nhÆ°ng diagnose bÃ¡o `UNREGISTERED_*` náº¿u rule enabled.

---

## Sample module

`Automation/Modules/Sample/SampleAutomationModuleProvider.php` â€” disabled trong config.

Báº­t test:

```php
SampleAutomationModuleProvider::class => true,
```

ÄÄƒng kÃ½ Ä‘á»§: event `sample.ping`, action `sample.echo`, operator `sample_starts_with`, health, menu, permission, setting.

---

## ThÃªm module má»›i (checklist)

1. Táº¡o `{Name}AutomationModuleProvider` implement `AutomationModuleProvider`.
2. ÄÄƒng kÃ½ events/actions/conditions trong `register()`.
3. ThÃªm class vÃ o `config/automation-modules.php` (`true`/`false`).
4. Handler classes trong module folder â€” khÃ´ng sá»­a `AutomationGraphExecutionService` / jobs.
5. Unit test registry (xem `AutomationModuleSdkTest`).

---

## Invariants (khÃ´ng Ä‘á»•i)

- Execution engine, queue, graph, versioning semantics giá»¯ nguyÃªn.
- DB rule lÆ°u `action_code` string â€” resolve handler qua registry lÃºc runtime.
- Draft khÃ´ng execute; published version immutable.
