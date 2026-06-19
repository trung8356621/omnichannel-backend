<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\SeoDatabaseConnection;
use App\Models\User;
use App\Services\SiteServiceBindingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class SeoDatabaseConnectionOwnerSync
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveOwnerId(array $data, ?SeoDatabaseConnection $record = null, mixed $rawOwnerId = null): int
    {
        $ownerId = (int) ($data['owner_id'] ?? 0);
        if ($ownerId > 0) {
            return $ownerId;
        }

        if (is_numeric($rawOwnerId) && (int) $rawOwnerId > 0) {
            return (int) $rawOwnerId;
        }

        if ($record === null) {
            return 0;
        }

        return (int) ($record->users()->value('users.id') ?? 0);
    }

    public static function assertOwnerEligible(int $ownerId): void
    {
        if ($ownerId <= 0) {
            throw ValidationException::withMessages([
                'owner_id' => 'Phải chọn owner cho workspace SEO này.',
            ]);
        }

        $user = User::query()->find($ownerId);
        if ($user === null || $user->role !== User::ROLE_OWNER) {
            throw ValidationException::withMessages([
                'owner_id' => 'Chỉ owner (role=owner) mới được gán SEO Database Connection.',
            ]);
        }

        app(SiteServiceBindingService::class)->assertOwnersHaveActiveSeoService([$ownerId]);
    }

    public static function assertOwnerSingleConnection(int $ownerId, ?int $exceptConnectionId = null): void
    {
        if ($ownerId <= 0) {
            return;
        }

        $query = SeoDatabaseConnection::query()
            ->whereHas('users', fn (Builder $builder): Builder => $builder->whereKey($ownerId));

        if ($exceptConnectionId !== null && $exceptConnectionId > 0) {
            $query->whereKeyNot($exceptConnectionId);
        }

        if (! $query->exists()) {
            return;
        }

        $user = User::query()->find($ownerId);

        throw ValidationException::withMessages([
            'owner_id' => sprintf(
                'Owner %s đã có SEO Database Connection khác. Mỗi owner chỉ được sở hữu một connection.',
                (string) ($user?->email ?? '#'.$ownerId),
            ),
        ]);
    }

    public static function syncOwner(SeoDatabaseConnection $connection, int $ownerId): void
    {
        $connection->users()->sync([$ownerId]);
        self::promoteOwnerToSeoManager($ownerId);
    }

    public static function promoteOwnerToSeoManager(int $ownerId): void
    {
        User::query()
            ->whereKey($ownerId)
            ->where('role', User::ROLE_OWNER)
            ->update(['seo_role' => User::SEO_ROLE_MANAGER]);
    }
}
