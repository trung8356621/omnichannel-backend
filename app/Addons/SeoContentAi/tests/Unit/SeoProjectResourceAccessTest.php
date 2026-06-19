<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Tests\TestCase;

final class SeoProjectResourceAccessTest extends TestCase
{
    public function test_content_manager_can_view_own_project_but_not_edit(): void
    {
        $staffId = 42;

        $user = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->id = $staffId;

        $this->actingAs($user);

        $owned = new SeoProject(['user_id' => $staffId]);
        $owned->id = 1;

        $foreign = new SeoProject(['user_id' => 99]);
        $foreign->id = 2;

        $this->assertTrue(SeoProjectResource::canView($owned));
        $this->assertFalse(SeoProjectResource::canEdit($owned));
        $this->assertFalse(SeoProjectResource::canView($foreign));
        $this->assertFalse(SeoProjectResource::canEdit($foreign));
    }

    public function test_planner_can_edit_projects_in_scope(): void
    {
        $this->actingAs(new User([
            'id' => 5,
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_PLANNER,
            'status' => User::STATUS_NORMAL,
        ]));

        $this->assertTrue(SeoAccessControl::canMutateContentProjects());
    }
}
