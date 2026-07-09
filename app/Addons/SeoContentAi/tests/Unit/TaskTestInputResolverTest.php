<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\TaskTestInputResolver;
use Tests\TestCase;

final class TaskTestInputResolverTest extends TestCase
{
    public function test_resolve_from_raw_input_sets_input_variables(): void
    {
        $resolver = app(TaskTestInputResolver::class);

        $context = $resolver->resolveFromRawInput('  Mô tả ảnh sản phẩm  ');

        $this->assertSame('Mô tả ảnh sản phẩm', $context->variables['input']);
        $this->assertSame('Mô tả ảnh sản phẩm', $context->variables['user_brief']);
        $this->assertStringContainsString('Mô tả ảnh sản phẩm', $context->summary);
    }

    public function test_resolve_from_raw_input_rejects_empty_string(): void
    {
        $resolver = app(TaskTestInputResolver::class);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveFromRawInput('   ');
    }
}
