<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SeoDatabaseConnectionUserIdsResolverTest extends TestCase
{
    public function test_normalize_user_ids_from_dehydrated_payload(): void
    {
        $userIds = $this->normalizeUserIds(['1', 2, 0, 'x']);

        $this->assertSame([1, 2], $userIds);
    }

    public function test_missing_dehydrated_users_falls_back_to_raw_state(): void
    {
        $data = ['name' => 'Workspace'];
        $rawState = ['users' => [3, 4]];

        $resolved = $this->resolveAllowedUserIds($data, $rawState, [5, 6]);

        $this->assertSame([3, 4], $resolved);
    }

    public function test_missing_users_uses_attached_record_ids(): void
    {
        $data = ['name' => 'Workspace'];
        $rawState = [];

        $resolved = $this->resolveAllowedUserIds($data, $rawState, [7, 8]);

        $this->assertSame([7, 8], $resolved);
    }

    /**
     * @return list<int>
     */
    private function resolveAllowedUserIds(array $data, array $rawState, array $attachedUserIds): array
    {
        $userIds = $this->normalizeUserIds($data['users'] ?? null);
        if ($userIds !== []) {
            return $userIds;
        }

        $userIds = $this->normalizeUserIds($rawState['users'] ?? null);
        if ($userIds !== []) {
            return $userIds;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $attachedUserIds),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * @return list<int>
     */
    private function normalizeUserIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $value),
            static fn (int $id): bool => $id > 0,
        ));
    }
}
