> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../operations/TROUBLESHOOTING.md
> Purpose: implementation history only
# Database cleanup â€” misplaced tables

## Má»¥c Ä‘Ã­ch

Dá»n table bá»‹ migration táº¡o nháº§m database (thÆ°á»ng 0 row) trong kiáº¿n trÃºc multi-DB:

| Logical owner | Laravel connection | Nguá»“n |
|---|---|---|
| `core` | `config('database.core_connection')` (thÆ°á»ng `mysql`) | `database/migrations` + GSC/SERP credentials + **`automation_*` / `business_events`** |
| SEO | `omi_seo_ai` | Addon `SeoContentAi` migrations/models (khÃ´ng cÃ²n automation schema) |
| WP Headless | `wp_headless` | Addon `WpHeadless` |

`automation_*` + `business_events` Ä‘Ã£ chuyá»ƒn sang core â€” xem `config/automation.php`, `php artisan automation:migrate-to-core`.

## Ownership

1. Config: `config/database_table_ownership.php`
2. Addon khai bÃ¡o qua `DeclaresDatabaseTableOwnership::databaseTableOwnership()`:
   - `SeoContentAiServiceProvider`
   - `WpHeadlessServiceProvider`

Registry: `App\Support\Database\DatabaseTableOwnershipRegistry`.

## Cháº¡y command

Dry-run (máº·c Ä‘á»‹nh, **khÃ´ng** mutate schema):

```bash
php artisan database:cleanup-misplaced-tables
php artisan database:cleanup-misplaced-tables --dry-run
```

Execute (cÃ³ xÃ¡c nháº­n):

```bash
php artisan database:cleanup-misplaced-tables --execute
```

CI / non-interactive:

```bash
php artisan database:cleanup-misplaced-tables --execute --force
```

`--force` **má»™t mÃ¬nh** khÃ´ng Ä‘á»§ Ä‘á»ƒ xÃ³a.

## Report

JSON audit:

`storage/app/database-cleanup/cleanup-YYYY-mm-dd-His.json`

KhÃ´ng ghi password / credential.

## Quy táº¯c an toÃ n

Chá»‰ DROP khi:

- ownership xÃ¡c Ä‘á»‹nh Ä‘Ãºng 1 connection owner;
- table náº±m á»Ÿ connection khÃ¡c owner;
- hai connection **khÃ´ng** cÃ¹ng database váº­t lÃ½ (so driver/host/port/database);
- row count = 0.

KhÃ´ng DROP: `UNKNOWN_OWNER`, `CONFLICT`, `NON_EMPTY`, `WARNING`, cÃ¹ng physical DB, connection unreachable.

`automation_*` / `business_events`: owner **core** (`config/database_table_ownership.php`). Empty copy trÃªn `omi_seo_ai` (sau cutover) cÃ³ thá»ƒ bá»‹ cleanup náº¿u policy cho phÃ©p; báº£n runtime trÃªn core khÃ´ng bá»‹ xÃ³a.

## Root cause (migration táº¡o nháº§m DB)

1. Addon SEO `loadMigrationsFrom()` Ä‘Æ°a migration multi-connection vÃ o migrator chung.
2. Laravel `Migration::$connection` **khÃ´ng** tá»± redirect `Schema::create()` â€” pháº£i gá»i `Schema::connection(...)`.
3. Má»™t sá»‘ migration (Ä‘áº·c biá»‡t giai Ä‘oáº¡n sá»›m / sá»­a tay nhiá»u láº§n) tá»«ng cháº¡y khi connection SEO chÆ°a bootstrap Ä‘Ãºng hoáº·c `omi_seo_ai` táº¡m trÃ¹ng DB váº­t lÃ½ vá»›i core â†’ sau khi tÃ¡ch DB cÃ²n láº¡i table stub 0 row.
4. Migration GSC/SERP náº±m trong thÆ° má»¥c SEO nhÆ°ng `protected $connection = 'mysql'` â€” dá»… nháº§m khi cháº¡y migrate theo â€œthÆ° má»¥c addonâ€.

## Test

```bash
php artisan test --filter=MisplacedTableCleanupTest
```

## Task tiáº¿p theo

`automation_*` Ä‘Ã£ migrate sang core (`automation:migrate-to-core`). Cleanup nguá»“n SEO chá»‰ khi verify/cutover á»•n Ä‘á»‹nh + flag `--cleanup-source --force`.
