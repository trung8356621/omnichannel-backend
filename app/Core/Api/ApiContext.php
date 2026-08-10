<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * Request/site context for API runtime. WordPress-specific fields stay in wordpress addon.
 */
final class ApiContext
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?int $siteId = null,
        public readonly array $scopes = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $correlationId = null,
        public readonly array $attributes = [],
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
