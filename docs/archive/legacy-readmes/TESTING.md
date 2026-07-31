> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../operations/TESTING.md
> Purpose: implementation history only
# Testing â€” PHPUnit discovery & conventions

## Framework

Project dÃ¹ng **PHPUnit 11** thuáº§n (`phpunit/phpunit` trong `require-dev`).

Pest **chÆ°a** Ä‘Æ°á»£c cÃ i (`pestphp/pest` khÃ´ng cÃ³ trong `composer.json`). `allow-plugins.pestphp/pest-plugin` chá»‰ lÃ  chá»— dÃ nh sáºµn â€” khÃ´ng cÃ³ nghÄ©a suite Ä‘ang cháº¡y Pest.

KhÃ´ng dÃ¹ng `php artisan optimize:clear` Ä‘á»ƒ â€œsá»­aâ€ lá»—i **No tests found**. ÄÃ³ lÃ  lá»—i discovery/config, khÃ´ng pháº£i cache config.

## Cáº¥u trÃºc & convention

### Core

| Loáº¡i | Path | Namespace | Base class |
|------|------|-----------|------------|
| Unit (Laravel) | `tests/Unit/...SomethingTest.php` | `Tests\Unit\...` | `Tests\TestCase` |
| Unit (thuáº§n) | `tests/Unit/...SomethingTest.php` | `Tests\Unit\...` | `PHPUnit\Framework\TestCase` |
| Feature | `tests/Feature/...SomethingTest.php` | `Tests\Feature\...` | `Tests\TestCase` |

### Addon SeoContentAi

| Loáº¡i | Path | Namespace |
|------|------|-----------|
| Unit | `app/Addons/SeoContentAi/tests/Unit/...Test.php` | `App\Addons\SeoContentAi\Tests\Unit\...` |
| Feature | `app/Addons/SeoContentAi/tests/Feature/...Test.php` | `App\Addons\SeoContentAi\Tests\Feature\...` |

Folder váº­t lÃ½ lÃ  `tests/` (chá»¯ thÆ°á»ng). Composer map trong **`autoload`** (khÃ´ng chá»‰ autoload-dev):

```json
"App\\Addons\\SeoContentAi\\Tests\\": "app/Addons/SeoContentAi/tests/"
```

Ä‘á»ƒ PSR-4 khá»›p trÃªn Linux (case-sensitive), ká»ƒ cáº£ khi ai Ä‘Ã³ dump autoload trÃªn server.

### Quy táº¯c báº¯t buá»™c

1. TÃªn file káº¿t thÃºc báº±ng `Test.php`.
2. TÃªn class = tÃªn file (khÃ´ng extension).
3. Namespace khá»›p Ä‘Æ°á»ng dáº«n theo map Composer (`autoload` / `autoload-dev`).
4. Má»™t file = má»™t class test (helper Ä‘áº·t `tests/Support` hoáº·c `.../tests/Support`, **khÃ´ng** dÃ¹ng háº­u tá»‘ `Test.php`).
5. Method: `test_...` / `testSomething` hoáº·c attribute `#[Test]` (`PHPUnit\Framework\Attributes\Test`).
6. KhÃ´ng Ä‘áº·t `*Test.php` ngoÃ i directory Ä‘Ã£ khai bÃ¡o trong `phpunit.xml`.

## phpunit.xml

Testsuites hiá»‡n táº¡i:

- `tests/Unit`
- `tests/Feature`
- `app/Addons/SeoContentAi/tests/Unit`
- `app/Addons/SeoContentAi/tests/Feature`

Má»—i suite dÃ¹ng `suffix="Test.php"`.

## Commands

```bash
# Audit discovery (á»•n Ä‘á»‹nh nháº¥t â€” khÃ´ng phá»¥ thuá»™c Collision)
composer test:doctor
php scripts/test-doctor.php
# hoáº·c (khi Ä‘Ã£ deploy command):
php artisan test:doctor

# ToÃ n bá»™ suite qua Composer â†’ PHPUnit trá»±c tiáº¿p
composer test
php scripts/run-phpunit.php

# Theo file (á»•n Ä‘á»‹nh trÃªn server)
php scripts/run-phpunit.php app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
composer test -- app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php

# Khi require-dev Ä‘á»§ (cÃ³ Collision):
php artisan test app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
php artisan test --filter='App\\Addons\\SeoContentAi\\Tests\\Unit\\ArticlePipelineRerunServiceTest'

# CI: doctor rá»“i suite
COMPOSER_ALLOW_SUPERUSER=1 composer test:ci
```

**KhÃ´ng** cháº¡y `php artisan test:ci` â€” Ä‘Ã³ khÃ´ng pháº£i Artisan command. DÃ¹ng `composer test:ci`.

### Lá»—i: `There are no commands defined in the "test" namespace`

NguyÃªn nhÃ¢n thÆ°á»ng gáº·p:

1. Server cÃ i `composer install --no-dev` â†’ thiáº¿u `nunomaduro/collision` + `phpunit/phpunit` â†’ khÃ´ng cÃ³ `php artisan test` / `test:doctor`.
2. ChÆ°a deploy `TestDoctorCommand` / `scripts/*` / `composer.json` má»›i.
3. GÃµ nháº§m `php artisan test:ci` thay vÃ¬ `composer test:ci`.

Sá»­a trÃªn server (root):

```bash
cd /path/to/app
COMPOSER_ALLOW_SUPERUSER=1 composer install
COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload
php scripts/test-doctor.php
php scripts/run-phpunit.php app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
COMPOSER_ALLOW_SUPERUSER=1 composer test:ci
```

`--filter` theo **tÃªn class/method PHPUnit**, khÃ´ng pháº£i Pest description (project khÃ´ng dÃ¹ng Pest).

Liá»‡t kÃª test runner discover:

```bash
./vendor/bin/phpunit --list-tests
./vendor/bin/phpunit --list-tests-xml phpunit-list.xml
```

## Root cause lá»‹ch sá»­: â€œNo tests foundâ€

`phpunit.xml` trÆ°á»›c Ä‘Ã¢y **chá»‰** khai bÃ¡o `tests/Unit` + `tests/Feature`.

Háº§u háº¿t test SEO náº±m á»Ÿ `app/Addons/SeoContentAi/tests/`. VÃ¬ váº­y:

- `php artisan test --filter=PromptExecutionOrchestrationTest` â†’ **No tests found**
- `php artisan test app/Addons/SeoContentAi/tests/Unit/PromptExecutionOrchestrationTest.php` â†’ cháº¡y Ä‘Æ°á»£c (path tÆ°á»ng minh)

ÄÃ£ sá»­a báº±ng cÃ¡ch thÃªm suite SeoContentAi vÃ o `phpunit.xml` + autoload-dev map + `test:doctor`.

## PhÃ¢n loáº¡i lá»—i

| Loáº¡i | Ã nghÄ©a | HÃ nh Ä‘á»™ng |
|------|---------|-----------|
| Discovery | File khÃ´ng vÃ o suite / sai tÃªn / sai namespace | `test:doctor` + sá»­a convention/config |
| Bootstrap | Class not found, autoload, syntax | `composer dump-autoload`, sá»­a file |
| Nghiá»‡p vá»¥ | Assertion fail / DB / skip | Sá»­a production hoáº·c fixture â€” **khÃ´ng** xÃ³a/skip test Ä‘á»ƒ xanh giáº£ |

## ThÃªm addon tests má»›i

1. Äáº·t dÆ°á»›i `app/Addons/{Addon}/tests/Unit|Feature/`.
2. Namespace `App\Addons\{Addon}\Tests\...`.
3. ThÃªm PSR-4 vÃ o `composer.json` `autoload-dev` náº¿u chÆ°a cÃ³.
4. ThÃªm `<directory suffix="Test.php">...</directory>` vÃ o `phpunit.xml`.
5. `composer dump-autoload`
6. `php artisan test:doctor`
