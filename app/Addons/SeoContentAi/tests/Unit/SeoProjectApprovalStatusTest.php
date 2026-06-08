<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoProject;
use PHPUnit\Framework\TestCase;

final class SeoProjectApprovalStatusTest extends TestCase
{
    public function test_approved_status_is_available_for_projects(): void
    {
        self::assertSame(
            'Đã duyệt',
            SeoProject::statusOptions()[SeoProject::STATUS_APPROVED],
        );
    }
}
