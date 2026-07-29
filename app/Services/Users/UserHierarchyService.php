<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Organizational hierarchy validation (Owner → Manager → Staff).
 * Does not replace RBAC / seo_role.
 */
final class UserHierarchyService
{
    /**
     * Normalize + validate hierarchy fields for create/update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeFormData(array $data, ?User $existing = null, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        $role = (string) ($data['role'] ?? $existing?->role ?? '');

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null
            ? (int) $data['parent_id']
            : null;
        $managerId = isset($data['manager_id']) && $data['manager_id'] !== '' && $data['manager_id'] !== null
            ? (int) $data['manager_id']
            : null;

        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_OWNER], true)) {
            $parentId = null;
            $managerId = null;
        } elseif ($role === User::ROLE_MANAGER) {
            $managerId = null;
        } elseif ($role !== User::ROLE_STAFF) {
            throw ValidationException::withMessages([
                'role' => 'Role không hợp lệ cho hierarchy.',
            ]);
        }

        // Owner actor chỉ được gán team của mình (Manager/Staff).
        if (
            $actor instanceof User
            && (string) $actor->role === User::ROLE_OWNER
            && in_array($role, [User::ROLE_MANAGER, User::ROLE_STAFF], true)
        ) {
            $parentId = (int) $actor->id;
        }

        if ($role === User::ROLE_MANAGER) {
            if ($parentId === null || $parentId <= 0) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Manager bắt buộc thuộc một Owner.',
                ]);
            }
            $this->assertIsOwner($parentId);
        }

        if ($role === User::ROLE_STAFF) {
            if ($parentId === null || $parentId <= 0) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Staff bắt buộc thuộc một Owner.',
                ]);
            }
            $this->assertIsOwner($parentId);

            if ($managerId !== null) {
                $this->assertManagerBelongsToOwner($managerId, $parentId);
            }
        }

        if ($existing instanceof User) {
            $this->assertSafeRoleChange($existing, $role);
        }

        if ($actor instanceof User && (string) $actor->role === User::ROLE_OWNER) {
            if ($parentId !== null && $parentId !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Owner chỉ được quản lý team của chính mình.',
                ]);
            }
        }

        $data['parent_id'] = $parentId;
        $data['manager_id'] = $managerId;

        return $data;
    }

    public function assertCanDelete(User $user): void
    {
        if ((string) $user->role === User::ROLE_OWNER) {
            $hasTeam = User::query()
                ->where('parent_id', $user->id)
                ->whereIn('role', [User::ROLE_MANAGER, User::ROLE_STAFF])
                ->exists();
            if ($hasTeam) {
                throw ValidationException::withMessages([
                    'role' => 'Owner đang có Manager/Staff. Hãy chuyển team sang Owner khác trước khi xóa.',
                ]);
            }
        }
    }

    /**
     * When deleting a Manager: keep staff under same Owner, clear manager_id.
     */
    public function detachStaffFromManager(User $manager): void
    {
        if ((string) $manager->role !== User::ROLE_MANAGER) {
            return;
        }

        User::query()
            ->where('manager_id', $manager->id)
            ->where('role', User::ROLE_STAFF)
            ->update(['manager_id' => null]);
    }

    /**
     * When Manager demoted/changed: unassign their staff.
     */
    public function handleManagerRoleChange(User $user, string $newRole): void
    {
        if ((string) $user->role !== User::ROLE_MANAGER) {
            return;
        }

        if ($newRole === User::ROLE_MANAGER) {
            return;
        }

        $this->detachStaffFromManager($user);
    }

    private function assertIsOwner(int $ownerId): void
    {
        $owner = User::query()->find($ownerId);
        if (! $owner instanceof User || (string) $owner->role !== User::ROLE_OWNER) {
            throw ValidationException::withMessages([
                'parent_id' => 'Owner được chọn không hợp lệ.',
            ]);
        }
    }

    private function assertManagerBelongsToOwner(int $managerId, int $ownerId): void
    {
        $manager = User::query()->find($managerId);
        if (
            ! $manager instanceof User
            || (string) $manager->role !== User::ROLE_MANAGER
            || (int) $manager->parent_id !== $ownerId
        ) {
            throw ValidationException::withMessages([
                'manager_id' => 'Manager phải thuộc Owner đã chọn.',
            ]);
        }
    }

    private function assertSafeRoleChange(User $existing, string $newRole): void
    {
        $oldRole = (string) $existing->role;
        if ($oldRole === $newRole) {
            return;
        }

        if ($oldRole === User::ROLE_OWNER && $newRole !== User::ROLE_OWNER) {
            $hasTeam = User::query()
                ->where('parent_id', $existing->id)
                ->whereIn('role', [User::ROLE_MANAGER, User::ROLE_STAFF])
                ->exists();
            if ($hasTeam) {
                throw ValidationException::withMessages([
                    'role' => 'Owner đang có team. Hãy reassignment trước khi đổi role.',
                ]);
            }
        }

        if ($oldRole === User::ROLE_MANAGER && $newRole !== User::ROLE_MANAGER) {
            // Staff will be unassigned in mutate before save.
            return;
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function ownersForSelect(?User $actor = null)
    {
        $actor ??= auth()->user();
        $query = User::query()->where('role', User::ROLE_OWNER)->orderBy('name');

        if ($actor instanceof User && (string) $actor->role === User::ROLE_OWNER) {
            $query->whereKey($actor->id);
        }

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function managersForOwner(?int $ownerId)
    {
        if ($ownerId === null || $ownerId <= 0) {
            return collect();
        }

        return User::query()
            ->where('role', User::ROLE_MANAGER)
            ->where('parent_id', $ownerId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
