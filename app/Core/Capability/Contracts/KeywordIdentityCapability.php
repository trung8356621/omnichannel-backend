<?php

declare(strict_types=1);

namespace App\Core\Capability\Contracts;

/**
 * Search Foundation — canonical keyword identity.
 */
interface KeywordIdentityCapability
{
    public function findIdByPhrase(int $siteId, string $phrase): ?int;

    /**
     * @return array{id:int,phrase:string,site_id:int}|null
     */
    public function getById(int $keywordId): ?array;
}
