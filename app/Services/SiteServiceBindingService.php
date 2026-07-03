<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SiteService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class SiteServiceBindingService
{
    public const BOUND_SITE = 'site';

    public const BOUND_USER = 'user';

    public function seoServiceSlug(): string
    {
        return (string) config('seo-content-ai.service_slug', 'seo-content-ai');
    }

    public function isSeoContentAiService(?int $serviceId): bool
    {
        if ($serviceId === null || $serviceId <= 0) {
            return false;
        }

        return \App\Models\Service::query()
            ->whereKey($serviceId)
            ->where('slug', $this->seoServiceSlug())
            ->exists();
    }

    public function ownerHasActiveSeoService(int $ownerId): bool
    {
        return $this->findActiveSeoServiceForOwner($ownerId) !== null;
    }

    /**
     * Owner (role=owner) đã có Site Service SEO Content AI active — dùng cho phân quyền connection.
     *
     * @param  list<int>  $includeUserIds  Giữ lựa chọn hiện tại khi sửa (kể cả owner mất service).
     * @return array<int|string, string>
     */
    public function eligibleOwnerSelectOptions(array $includeUserIds = []): array
    {
        $includeUserIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $includeUserIds),
            static fn (int $id): bool => $id > 0,
        ));

        $query = User::query()
            ->where('role', User::ROLE_OWNER)
            ->where(function (Builder $builder) use ($includeUserIds): void {
                $builder->whereIn('id', $this->ownerIdsWithActiveSeoServiceSubquery());

                if ($includeUserIds !== []) {
                    $builder->orWhereIn('id', $includeUserIds);
                }
            })
            ->orderBy('email');

        return $query->pluck('email', 'id')->all();
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function ownerIdsWithActiveSeoServiceSubquery()
    {
        $seoServiceIds = \App\Models\Service::query()
            ->where('slug', $this->seoServiceSlug())
            ->select('id');

        $userBound = SiteService::query()
            ->select('user_id')
            ->where('status', 'active')
            ->where('bound_type', self::BOUND_USER)
            ->whereNotNull('user_id')
            ->whereIn('service_id', $seoServiceIds);

        $siteBound = SiteService::query()
            ->select('sites.user_id')
            ->join('sites', 'site_services.site_id', '=', 'sites.id')
            ->where('site_services.status', 'active')
            ->where(function (Builder $query): void {
                $query->where('site_services.bound_type', self::BOUND_SITE)
                    ->orWhereNull('site_services.bound_type');
            })
            ->whereNotNull('site_services.site_id')
            ->whereIn('site_services.service_id', $seoServiceIds);

        return User::query()
            ->select('id')
            ->where('role', User::ROLE_OWNER)
            ->where(function (Builder $query) use ($userBound, $siteBound): void {
                $query->whereIn('id', $userBound)
                    ->orWhereIn('id', $siteBound);
            });
    }

    public function findActiveSeoServiceForOwner(int $ownerId): ?SiteService
    {
        if ($ownerId <= 0) {
            return null;
        }

        return SiteService::query()
            ->where('status', 'active')
            ->whereHas('service', fn (Builder $query): Builder => $query->where('slug', $this->seoServiceSlug()))
            ->where(function (Builder $query) use ($ownerId): void {
                $query->where(function (Builder $sub) use ($ownerId): void {
                    $sub->where('bound_type', self::BOUND_USER)
                        ->where('user_id', $ownerId);
                })->orWhere(function (Builder $sub) use ($ownerId): void {
                    $sub->where(function (Builder $inner): void {
                        $inner->where('bound_type', self::BOUND_SITE)
                            ->orWhereNull('bound_type');
                    })->whereHas('site', fn (Builder $site): Builder => $site->where('user_id', $ownerId));
                });
            })
            ->orderBy('id')
            ->first();
    }

    public function resolveOwnerId(SiteService $record): int
    {
        if ($record->isBoundToUser()) {
            return (int) ($record->user_id ?? 0);
        }

        $record->loadMissing('site');

        return (int) ($record->site?->user_id ?? 0);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeBoundPayload(array $data): array
    {
        $boundType = (string) ($data['bound_type'] ?? self::BOUND_SITE);
        if (! in_array($boundType, [self::BOUND_SITE, self::BOUND_USER], true)) {
            $boundType = self::BOUND_SITE;
        }

        $data['bound_type'] = $boundType;

        if ($boundType === self::BOUND_USER) {
            $data['site_id'] = null;
        } else {
            $data['user_id'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertBoundPayload(array $data): void
    {
        $boundType = (string) ($data['bound_type'] ?? self::BOUND_SITE);

        if ($boundType === self::BOUND_USER) {
            $userId = (int) ($data['user_id'] ?? 0);
            if ($userId <= 0) {
                throw ValidationException::withMessages([
                    'user_id' => __('site-service.bound_select_owner'),
                ]);
            }

            $user = User::query()->find($userId);
            if ($user === null || $user->role !== User::ROLE_OWNER) {
                throw ValidationException::withMessages([
                    'user_id' => __('site-service.bound_owner_only'),
                ]);
            }

            return;
        }

        $siteId = (int) ($data['site_id'] ?? 0);
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('site-service.bound_select_site'),
            ]);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    public function assertOwnersHaveActiveSeoService(array $userIds): void
    {
        $messages = [];

        foreach ($userIds as $userId) {
            $ownerId = (int) $userId;
            if ($ownerId <= 0) {
                continue;
            }

            $user = User::query()->find($ownerId);
            if ($user === null || $user->role !== User::ROLE_OWNER) {
                continue;
            }

            if ($this->ownerHasActiveSeoService($ownerId)) {
                continue;
            }

            $messages[] = sprintf(
                'Owner %s (ID %d) chưa có Site Service SEO Content AI đang active (ràng buộc site hoặc user).',
                (string) $user->email,
                $ownerId,
            );
        }

        if ($messages !== []) {
            throw ValidationException::withMessages([
                'users' => implode(' ', $messages),
            ]);
        }
    }
}
