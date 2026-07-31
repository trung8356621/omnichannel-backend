<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\ContentProjectRerunFromStep;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\RerunProjectItemStepHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Batch B — step rerun lives in CommandBus handler (legacy StepRerunService no longer production path).
 */
final class ContentProjectStepRerunPhase20Test extends TestCase
{
    public function test_step_handler_owns_orchestration(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RerunProjectItemStepHandler::class))->getFileName(),
        );
        self::assertStringContainsString('prepareRunQueue', $src);
        self::assertStringContainsString('runEngine->start', $src);
        self::assertStringContainsString('validateStep', $src);
    }

    public function test_eligibility_guard_covers_source_contracts(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectRerunEligibilityGuard::class))->getFileName(),
        );
        self::assertStringContainsString('Article-only rerun requires a usable outline', $src);
        self::assertStringContainsString('Outline rerun requires title or keyword', $src);
        self::assertStringContainsString('Improve items are manual-only', $src);
    }

    public function test_from_step_enum(): void
    {
        self::assertSame(ContentProjectRerunFromStep::Outline, ContentProjectRerunFromStep::fromMixed('outline'));
        self::assertSame(ContentProjectRerunFromStep::Article, ContentProjectRerunFromStep::fromMixed('article'));
    }
}
