<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Staff (role=staff + seo_role=content_manager) chưa gắn writer vào Content Project active.
 * Assignment thực tế = seo_projects.user_id (không có pivot riêng).
 */
final class ContentProjectStaffAvailabilityService
{
    public const WIDGET_LIMIT = 8;

    public function canViewUnassignedStaff(): bool
    {
        return SeoAccessControl::canMutateContentProjects();
    }

    /**
     * @return Builder<User>
     */
    public function baseAssignableStaffQuery(): Builder
    {
        $query = User::query()
            ->where('role', User::ROLE_STAFF)
            ->where('seo_role', User::SEO_ROLE_CONTENT_MANAGER)
            ->where('status', User::STATUS_NORMAL);

        if (auth()->user()?->role !== User::ROLE_ADMIN) {
            $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->where('parent_id', $ownerId);
        }

        return $query->orderBy('name');
    }

    /**
     * @return Builder<User>
     */
    public function unassignedStaffQuery(?string $search = null): Builder
    {
        $assignedIds = $this->activeAssignedStaffIds();

        $query = $this->baseAssignableStaffQuery();

        if ($assignedIds !== []) {
            $query->whereNotIn('id', $assignedIds);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @return list<int>
     */
    public function activeAssignedStaffIds(): array
    {
        return SeoProject::query()
            ->activeProjects()
            ->where(function (Builder $builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->whereNotNull('user_id')
            ->where('user_id', '>', 0)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    public function isUnassigned(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $this->unassignedStaffQuery()
            ->whereKey($userId)
            ->exists();
    }

    /**
     * @return Collection<int, User>
     */
    public function listUnassigned(?string $search = null, ?int $limit = null): Collection
    {
        $query = $this->unassignedStaffQuery($search);

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * @return array{total: int, staff: list<array{id: int, name: string, email: string, initials: string, create_url: string}>}
     */
    public function widgetPayload(int $limit = self::WIDGET_LIMIT): array
    {
        $total = (int) $this->unassignedStaffQuery()->count();
        $staff = $this->listUnassigned(null, $limit)
            ->map(fn (User $user): array => $this->presentStaff($user))
            ->values()
            ->all();

        return [
            'total' => $total,
            'staff' => $staff,
        ];
    }

    /**
     * @return array{unassigned: array<int, string>, assigned: array<int, string>}
     */
    public function groupedSelectOptions(?string $search = null): array
    {
        $assignedIds = $this->activeAssignedStaffIds();
        $all = $this->baseAssignableStaffQuery();

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $all->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $unassigned = [];
        $assigned = [];

        foreach ($all->get(['id', 'name', 'email']) as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $label = $this->formatLabel($user);
            $id = (int) $user->getKey();

            if (in_array($id, $assignedIds, true)) {
                $assigned[$id] = $label;
            } else {
                $unassigned[$id] = $label;
            }
        }

        return [
            'unassigned' => $unassigned,
            'assigned' => $assigned,
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, initials: string, create_url: string}
     */
    public function presentStaff(User $user): array
    {
        $name = trim((string) ($user->display_name ?: $user->name ?: ''));
        $email = trim((string) ($user->email ?? ''));

        return [
            'id' => (int) $user->getKey(),
            'name' => $name !== '' ? $name : ($email !== '' ? $email : '#'.$user->getKey()),
            'email' => $email,
            'initials' => $this->initials($name !== '' ? $name : $email),
            'create_url' => $this->createProjectUrl((int) $user->getKey()),
        ];
    }

    public function createProjectUrl(int $userId): string
    {
        $base = \App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource::getUrl('create');

        if ($userId <= 0) {
            return $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').'writer_id='.$userId;
    }

    public function formatLabel(User $user): string
    {
        $name = trim((string) ($user->display_name ?? ''));
        $email = trim((string) ($user->email ?? ''));

        if ($name !== '' && $email !== '') {
            return sprintf('%s(%s)', $name, $email);
        }

        if ($name !== '') {
            return $name;
        }

        if ($email !== '') {
            return $email;
        }

        $fallbackName = trim((string) ($user->name ?? ''));

        return $fallbackName !== '' ? $fallbackName : '#'.(int) $user->getKey();
    }

    private function initials(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $label) ?: [];
        $chars = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $chars[] = mb_strtoupper(mb_substr($part, 0, 1));
            if (count($chars) >= 2) {
                break;
            }
        }

        if ($chars === []) {
            return '?';
        }

        return implode('', $chars);
    }
}
