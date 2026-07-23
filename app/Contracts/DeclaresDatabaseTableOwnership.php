<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Addon/provider khai báo table thuộc connection nào.
 *
 * @phpstan-type OwnershipDeclaration array{
 *     connection: string,
 *     tables?: list<string>,
 *     patterns?: list<string>
 * }
 */
interface DeclaresDatabaseTableOwnership
{
    /**
     * @return OwnershipDeclaration|list<OwnershipDeclaration>
     */
    public function databaseTableOwnership(): array;
}
