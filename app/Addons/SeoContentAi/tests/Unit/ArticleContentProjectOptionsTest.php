<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ArticleContentProjectOptionsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'omi_seo_ai'];

    public function test_manager_sees_writer_assigned_project_with_zero_tasks(): void
    {
        Carbon::setTestNow('2026-07-13 10:00:00');

        $owner = User::query()->create([
            'name' => 'Account Owner',
            'email' => 'owner-content-project-options@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $writer = User::query()->create([
            'name' => 'Trang Writer',
            'email' => 'writer-content-project-options@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
        ]);

        $site = Site::query()->create([
            'user_id' => $owner->id,
            'name' => 'Cong ty balo',
            'domain' => 'congtybalo-options.test',
            'status' => 'active',
        ]);

        $project = SeoProject::query()->create([
            'name' => 'project 7/2026',
            'user_id' => $writer->id,
            'site_id' => $site->id,
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_MANUAL,
            'total_tasks' => 0,
        ]);

        $this->actingAs($owner);

        $options = ArticleResource::contentProjectOptions((int) $site->id);

        $this->assertArrayHasKey((int) $project->id, $options);

        Carbon::setTestNow();
    }

    public function test_content_manager_only_sees_own_assignable_projects(): void
    {
        Carbon::setTestNow('2026-07-13 10:00:00');

        $owner = User::query()->create([
            'name' => 'Account Owner',
            'email' => 'owner-content-project-options-cm@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $writer = User::query()->create([
            'name' => 'Assigned Writer',
            'email' => 'writer-content-project-options-cm@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
        ]);

        $otherWriter = User::query()->create([
            'name' => 'Other Writer',
            'email' => 'other-writer-content-project-options-cm@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
        ]);

        $site = Site::query()->create([
            'user_id' => $owner->id,
            'name' => 'Test domain',
            'domain' => 'options-cm.test',
            'status' => 'active',
        ]);

        $ownProject = SeoProject::query()->create([
            'name' => 'project own',
            'user_id' => $writer->id,
            'site_id' => $site->id,
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_MANUAL,
            'total_tasks' => 0,
        ]);

        $foreignProject = SeoProject::query()->create([
            'name' => 'project foreign',
            'user_id' => $otherWriter->id,
            'site_id' => $site->id,
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_MANUAL,
            'total_tasks' => 0,
        ]);

        $this->actingAs($writer);

        $options = ArticleResource::contentProjectOptions((int) $site->id);

        $this->assertArrayHasKey((int) $ownProject->id, $options);
        $this->assertArrayNotHasKey((int) $foreignProject->id, $options);

        Carbon::setTestNow();
    }
}
