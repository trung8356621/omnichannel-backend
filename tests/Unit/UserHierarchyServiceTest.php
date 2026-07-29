<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UserHierarchyServiceTest extends TestCase
{
    public function test_user_model_defines_manager_role_and_relations(): void
    {
        self::assertSame('manager', User::ROLE_MANAGER);
        $reflection = new ReflectionClass(User::class);
        self::assertTrue($reflection->hasMethod('manager'));
        self::assertTrue($reflection->hasMethod('managers'));
        self::assertTrue($reflection->hasMethod('staffMembers'));
        self::assertTrue($reflection->hasMethod('directStaffMembers'));
        self::assertTrue($reflection->hasMethod('accountOwnerId'));

        $fillable = (new User)->getFillable();
        self::assertContains('manager_id', $fillable);
        self::assertContains('parent_id', $fillable);
    }

    public function test_hierarchy_service_clears_links_for_owner_and_admin(): void
    {
        $service = new UserHierarchyService;

        $ownerData = $service->normalizeFormData([
            'role' => User::ROLE_OWNER,
            'parent_id' => 99,
            'manager_id' => 88,
            'name' => 'Owner',
            'email' => 'o@example.com',
        ], actor: null);

        self::assertNull($ownerData['parent_id']);
        self::assertNull($ownerData['manager_id']);

        $adminData = $service->normalizeFormData([
            'role' => User::ROLE_ADMIN,
            'parent_id' => 99,
            'manager_id' => 88,
            'name' => 'Admin',
            'email' => 'a@example.com',
        ], actor: null);

        self::assertNull($adminData['parent_id']);
        self::assertNull($adminData['manager_id']);
    }

    public function test_manager_requires_owner(): void
    {
        $service = new UserHierarchyService;

        $this->expectException(ValidationException::class);
        $service->normalizeFormData([
            'role' => User::ROLE_MANAGER,
            'parent_id' => null,
            'name' => 'Mgr',
            'email' => 'm@example.com',
        ], actor: null);
    }

    public function test_migration_adds_manager_id(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_07_29_100000_add_manager_id_to_users_table.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('manager_id', $source);
        self::assertStringContainsString('nullOnDelete', $source);
    }

    public function test_user_resource_exposes_owner_manager_filters(): void
    {
        $path = dirname(__DIR__, 2).'/app/Filament/Resources/UserResource.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('ROLE_MANAGER', $source);
        self::assertStringContainsString('unassigned_staff', $source);
        self::assertStringContainsString('owner.name', $source);
        self::assertStringContainsString('manager.name', $source);
        self::assertStringContainsString('UserHierarchyService', $source);
    }

    public function test_admin_panel_uses_full_content_width(): void
    {
        $path = dirname(__DIR__, 2).'/app/Providers/Filament/AdminPanelProvider.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('maxContentWidth', $source);
        self::assertStringContainsString('MaxWidth::Full', $source);
    }
}
