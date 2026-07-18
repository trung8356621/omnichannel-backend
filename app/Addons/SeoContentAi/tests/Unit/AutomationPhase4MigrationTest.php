<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Enums\MigrationMode;
use App\Addons\SeoContentAi\Automation\Migration\AutomationCallerMigrator;
use App\Addons\SeoContentAi\Automation\Migration\AutomationMigrationFlags;
use App\Addons\SeoContentAi\Automation\Migration\AutomationParityLogger;
use App\Addons\SeoContentAi\Automation\Migration\AutomationParitySampleRecorder;
use App\Addons\SeoContentAi\Automation\Support\ArticleContentConflictGuard;
use App\Addons\SeoContentAi\Automation\Support\ArticleCreateOriginResolver;
use App\Addons\SeoContentAi\Automation\Support\SensitivePayloadRedactor;
use App\Addons\SeoContentAi\Models\SeoArticle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class AutomationPhase4MigrationTest extends TestCase
{
    private function migrator(): AutomationCallerMigrator
    {
        return new AutomationCallerMigrator(
            new AutomationMigrationFlags,
            new AutomationParityLogger(new SensitivePayloadRedactor, new AutomationParitySampleRecorder),
        );
    }
    public function test_migration_mode_from_config(): void
    {
        self::assertSame(MigrationMode::Legacy, MigrationMode::fromConfig('legacy'));
        self::assertSame(MigrationMode::Shadow, MigrationMode::fromConfig('shadow'));
        self::assertSame(MigrationMode::Action, MigrationMode::fromConfig('action'));
        self::assertSame(MigrationMode::Legacy, MigrationMode::fromConfig('garbage'));
        self::assertTrue(MigrationMode::Shadow->writesViaLegacy());
        self::assertFalse(MigrationMode::Shadow->writesViaAction());
        self::assertTrue(MigrationMode::Action->writesViaAction());
        self::assertTrue(MigrationMode::Shadow->evaluatesParity());
    }

    public function test_flags_default_legacy(): void
    {
        Config::set('seo-content-ai.automation_migration', []);
        $flags = new AutomationMigrationFlags;
        self::assertSame(MigrationMode::Legacy, $flags->mode(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT));
    }

    public function test_content_conflict_guard_hash_mismatch(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = new SeoArticle;
        $article->body = 'alpha';
        $article->updated_at = Carbon::parse('2026-07-01T10:00:00+00:00');

        self::assertNull($guard->assertCompatible($article, [
            'expected_content_hash' => $guard->contentHash('alpha'),
        ]));

        $fail = $guard->assertCompatible($article, [
            'expected_content_hash' => $guard->contentHash('beta'),
        ]);
        self::assertInstanceOf(ActionResult::class, $fail);
        self::assertFalse($fail->success);
        self::assertSame('conflict_content_hash', $fail->error['code'] ?? null);
    }

    public function test_content_conflict_guard_updated_at_mismatch(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = new SeoArticle;
        $article->body = 'x';
        $article->updated_at = Carbon::parse('2026-07-01T10:00:00+00:00');

        $fail = $guard->assertCompatible($article, [
            'expected_updated_at' => '2026-07-01T09:00:00+00:00',
        ]);
        self::assertNotNull($fail);
        self::assertSame('conflict_updated_at', $fail->error['code'] ?? null);
    }

    public function test_content_hash_stable(): void
    {
        $guard = new ArticleContentConflictGuard;
        self::assertSame(
            $guard->contentHash("  hello \n"),
            $guard->contentHash('hello'),
        );
    }

    public function test_origin_resolver_constants(): void
    {
        self::assertSame('seo_project_task', ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK);
    }

    public function test_migrator_legacy_only_calls_legacy(): void
    {
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'legacy');

        $legacyCalls = 0;
        $actionCalls = 0;
        $parityCalls = 0;

        $migrator = $this->migrator();

        $out = $migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['added' => 1];
            },
            actionWrite: static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['added' => 1]);
            },
            parityExpected: static function () use (&$parityCalls): array {
                $parityCalls++;

                return ['added' => 1];
            },
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
            actionKey: 'seo.project_task.create_from_issue',
        );

        self::assertSame(['added' => 1], $out);
        self::assertSame(1, $legacyCalls);
        self::assertSame(0, $actionCalls);
        self::assertSame(0, $parityCalls);
    }

    public function test_migrator_shadow_no_action_write_and_parity_match(): void
    {
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'shadow');

        $legacyCalls = 0;
        $actionCalls = 0;
        $parityCalls = 0;

        Log::shouldReceive('info')->once()->withArgs(static function (string $message): bool {
            return $message === 'automation.migration.parity_match';
        });

        $migrator = $this->migrator();

        $out = $migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['added' => 1, 'duplicate' => 0];
            },
            actionWrite: static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['added' => 99]);
            },
            parityExpected: static function () use (&$parityCalls): array {
                $parityCalls++;

                return ['added' => 1, 'duplicate' => 0];
            },
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
            actionKey: 'seo.project_task.create_from_issue',
        );

        self::assertSame(1, $legacyCalls);
        self::assertSame(1, $parityCalls);
        self::assertSame(0, $actionCalls);
        self::assertSame(['added' => 1, 'duplicate' => 0], $out);
    }

    public function test_migrator_shadow_logs_mismatch(): void
    {
        Config::set('seo-content-ai.automation_migration.keyword_project_assignment', 'shadow');

        Log::shouldReceive('warning')->once()->withArgs(static function (string $message): bool {
            return $message === 'automation.migration.parity_mismatch';
        });

        $migrator = $this->migrator();

        $migrator->run(
            callerKey: AutomationMigrationFlags::KEYWORD_PROJECT_ASSIGNMENT,
            legacyWrite: static fn (): array => ['added' => 1],
            actionWrite: static fn (): ActionResult => ActionResult::success(),
            parityExpected: static fn (): array => ['added' => 0],
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
            actionKey: 'keyword.assign_to_project',
        );
    }

    public function test_migrator_action_skips_legacy(): void
    {
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'action');

        $legacyCalls = 0;
        $actionCalls = 0;

        $migrator = $this->migrator();

        $result = $migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['added' => 1];
            },
            actionWrite: static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['added' => 2]);
            },
            parityExpected: static fn (): array => [],
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
        );

        self::assertInstanceOf(ActionResult::class, $result);
        self::assertSame(0, $legacyCalls);
        self::assertSame(1, $actionCalls);
        self::assertSame(2, $result->output['added']);
    }

    public function test_rollback_to_legacy_via_flag(): void
    {
        Config::set('seo-content-ai.automation_migration.project_task_complete', 'action');
        $flags = new AutomationMigrationFlags;
        self::assertSame(MigrationMode::Action, $flags->mode(AutomationMigrationFlags::PROJECT_TASK_COMPLETE));

        Config::set('seo-content-ai.automation_migration.project_task_complete', 'legacy');
        self::assertSame(MigrationMode::Legacy, $flags->mode(AutomationMigrationFlags::PROJECT_TASK_COMPLETE));
    }
}
