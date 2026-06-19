<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\SeoDatabaseConnection;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Database\Eloquent\Builder;

final class SeoDatabaseConnectionAccess
{
    public static function isAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user?->role === User::ROLE_ADMIN;
    }

    public static function isEligibleOwner(?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return false;
        }

        return app(SiteServiceBindingService::class)->ownerHasActiveSeoService((int) $user->id);
    }

    public static function canAccessResource(): bool
    {
        return self::isAdmin() || self::isEligibleOwner();
    }

    public static function canCreateConnection(): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        return self::isEligibleOwner() && ! self::ownerHasConnection();
    }

    public static function canEditConnection(SeoDatabaseConnection $record): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        $user = auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return false;
        }

        return $record->users()->whereKey($user->id)->exists();
    }

    public static function canDeleteConnection(): bool
    {
        return self::isAdmin();
    }

    public static function ownerHasConnection(?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return false;
        }

        return SeoDatabaseConnection::query()
            ->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->id))
            ->exists();
    }

    /**
     * @return list<int>
     */
    public static function resolveOwnerUserIdsForSave(): array
    {
        if (self::isAdmin()) {
            return [];
        }

        $user = auth()->user();
        if ($user?->role !== User::ROLE_OWNER) {
            return [];
        }

        return [(int) $user->id];
    }
}
