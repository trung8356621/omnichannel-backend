<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Workspace\Contracts;

use App\Addons\SeoContentAi\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;

/**
 * Một module dọn AI Workspace khi Archive Content Project.
 * Module mới chỉ cần implement contract này rồi đăng ký vào Registry.
 */
interface ContentProjectWorkspaceCleaner
{
    public function key(): string;

    /**
     * Xóa dữ liệu workspace trong transaction hiện tại.
     * Không xóa file disk tại đây — trả path qua context để xóa sau commit.
     */
    public function clean(ContentProjectWorkspaceCleanupContext $context): void;
}
