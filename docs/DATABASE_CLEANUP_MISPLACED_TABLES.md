# Database cleanup — misplaced tables

## Mục đích

Dọn table bị migration tạo nhầm database (thường 0 row) trong kiến trúc multi-DB:

| Logical owner | Laravel connection | Nguồn |
|---|---|---|
| `core` | `config('database.core_connection')` (thường `mysql`) | `database/migrations` + GSC/SERP credentials + **`automation_*` / `business_events`** |
| SEO | `omi_seo_ai` | Addon `SeoContentAi` migrations/models (không còn automation schema) |
| WP Headless | `wp_headless` | Addon `WpHeadless` |

`automation_*` + `business_events` đã chuyển sang core — xem `config/automation.php`, `php artisan automation:migrate-to-core`.

## Ownership

1. Config: `config/database_table_ownership.php`
2. Addon khai báo qua `DeclaresDatabaseTableOwnership::databaseTableOwnership()`:
   - `SeoContentAiServiceProvider`
   - `WpHeadlessServiceProvider`

Registry: `App\Support\Database\DatabaseTableOwnershipRegistry`.

## Chạy command

Dry-run (mặc định, **không** mutate schema):

```bash
php artisan database:cleanup-misplaced-tables
php artisan database:cleanup-misplaced-tables --dry-run
```

Execute (có xác nhận):

```bash
php artisan database:cleanup-misplaced-tables --execute
```

CI / non-interactive:

```bash
php artisan database:cleanup-misplaced-tables --execute --force
```

`--force` **một mình** không đủ để xóa.

## Report

JSON audit:

`storage/app/database-cleanup/cleanup-YYYY-mm-dd-His.json`

Không ghi password / credential.

## Quy tắc an toàn

Chỉ DROP khi:

- ownership xác định đúng 1 connection owner;
- table nằm ở connection khác owner;
- hai connection **không** cùng database vật lý (so driver/host/port/database);
- row count = 0.

Không DROP: `UNKNOWN_OWNER`, `CONFLICT`, `NON_EMPTY`, `WARNING`, cùng physical DB, connection unreachable.

`automation_*` / `business_events`: owner **core** (`config/database_table_ownership.php`). Empty copy trên `omi_seo_ai` (sau cutover) có thể bị cleanup nếu policy cho phép; bản runtime trên core không bị xóa.

## Root cause (migration tạo nhầm DB)

1. Addon SEO `loadMigrationsFrom()` đưa migration multi-connection vào migrator chung.
2. Laravel `Migration::$connection` **không** tự redirect `Schema::create()` — phải gọi `Schema::connection(...)`.
3. Một số migration (đặc biệt giai đoạn sớm / sửa tay nhiều lần) từng chạy khi connection SEO chưa bootstrap đúng hoặc `omi_seo_ai` tạm trùng DB vật lý với core → sau khi tách DB còn lại table stub 0 row.
4. Migration GSC/SERP nằm trong thư mục SEO nhưng `protected $connection = 'mysql'` — dễ nhầm khi chạy migrate theo “thư mục addon”.

## Test

```bash
php artisan test --filter=MisplacedTableCleanupTest
```

## Task tiếp theo

`automation_*` đã migrate sang core (`automation:migrate-to-core`). Cleanup nguồn SEO chỉ khi verify/cutover ổn định + flag `--cleanup-source --force`.
