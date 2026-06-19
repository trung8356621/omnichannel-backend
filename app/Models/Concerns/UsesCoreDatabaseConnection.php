<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait UsesCoreDatabaseConnection
{
    /**
     * Luôn dùng DB core (mysql) — không phụ thuộc database.default có thể trùng tên connection addon.
     */
    public function getConnectionName(): ?string
    {
        return (string) config('database.core_connection', 'mysql');
    }
}
