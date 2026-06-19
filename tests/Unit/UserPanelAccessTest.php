<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Filament\Panel;
use Tests\TestCase;

final class UserPanelAccessTest extends TestCase
{
    public function test_staff_cannot_access_admin_panel(): void
    {
        $user = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);

        $panel = $this->panelWithId('admin');

        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_owner_can_access_admin_panel(): void
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $panel = $this->panelWithId('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_staff_with_owner_link_can_access_seo_panel(): void
    {
        $user = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);

        $panel = $this->panelWithId('seo');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    private function panelWithId(string $id): Panel
    {
        $panel = $this->createMock(Panel::class);
        $panel->method('getId')->willReturn($id);

        return $panel;
    }
}
