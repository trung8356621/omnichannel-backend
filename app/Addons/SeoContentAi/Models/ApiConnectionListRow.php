<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Services\GoogleSearchConsoleConnectionService;
use App\Addons\SeoContentAi\Support\ApiConnectionProviders;
use App\Addons\SeoContentAi\Support\SerpProviderKeys;
use Illuminate\Database\Eloquent\Model;

final class ApiConnectionListRow extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getTable(): string
    {
        return 'api_connection_list_rows';
    }

    public static function extractGscId(string $rowId): ?int
    {
        if (! str_starts_with($rowId, 'gsc:')) {
            return null;
        }

        $id = (int) substr($rowId, 4);

        return $id > 0 ? $id : null;
    }

    public static function extractDfsId(string $rowId): ?int
    {
        if (! str_starts_with($rowId, 'dfs:')) {
            return null;
        }

        $id = (int) substr($rowId, 4);

        return $id > 0 ? $id : null;
    }

    public static function fromGsc(SeoGscMasterConnection $connection): self
    {
        $service = app(GoogleSearchConsoleConnectionService::class);
        $effectiveStatus = $service->resolveEffectiveStatus($connection);

        $row = new self;
        $row->forceFill([
            'id' => 'gsc:'.$connection->id,
            'name' => (string) ($connection->name ?: 'Google Search Console'),
            'provider' => ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE,
            'status' => $effectiveStatus,
        ]);
        $row->exists = true;

        return $row;
    }

    public static function fromDataForSeo(SeoDataForSeoConnection $connection): self
    {
        $row = new self;
        $row->forceFill([
            'id' => 'dfs:'.$connection->id,
            'name' => (string) ($connection->login ?: 'DataForSEO'),
            'provider' => ApiConnectionProviders::DATAFORSEO,
            'status' => (string) ($connection->status ?? 'not_configured'),
        ]);
        $row->exists = true;

        return $row;
    }

    public static function extractSerpId(string $rowId): ?int
    {
        if (! str_starts_with($rowId, 'serp:')) {
            return null;
        }

        $id = (int) substr($rowId, 5);

        return $id > 0 ? $id : null;
    }

    public static function fromSerpProvider(SeoSerpProviderConnection $connection): self
    {
        $row = new self;
        $row->forceFill([
            'id' => 'serp:'.$connection->id,
            'name' => (string) ($connection->name ?: SerpProviderKeys::label((string) $connection->provider)),
            'provider' => (string) $connection->provider,
            'status' => (string) ($connection->status ?? 'not_configured'),
        ]);
        $row->exists = true;

        return $row;
    }
}
