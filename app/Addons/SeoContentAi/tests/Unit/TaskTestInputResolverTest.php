<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use PHPUnit\Framework\TestCase;

final class TaskTestInputResolverTest extends TestCase
{
    public function test_requires_at_least_one_input(): void
    {
        $resolver = new TaskTestInputResolver($this->createStub(SeoAnalyzerService::class));

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve(null, null, null);
    }
}
