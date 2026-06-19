<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SiteServiceBindingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteService extends Model
{
    use HasFactory;

    protected $table = 'site_services';

    protected $fillable = [
        'bound_type',
        'user_id',
        'site_id',
        'service_id',
        'status',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'status' => 'string',
        'user_id' => 'integer',
        'site_id' => 'integer',
    ];

    protected $attributes = [
        'bound_type' => SiteServiceBindingService::BOUND_SITE,
    ];

    public function isBoundToSite(): bool
    {
        $type = (string) ($this->bound_type ?? SiteServiceBindingService::BOUND_SITE);

        return $type === SiteServiceBindingService::BOUND_SITE || $type === '';
    }

    public function isBoundToUser(): bool
    {
        return (string) ($this->bound_type ?? '') === SiteServiceBindingService::BOUND_USER;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function boundLabel(): string
    {
        if ($this->isBoundToUser()) {
            $this->loadMissing('user');

            return (string) ($this->user?->email ?? 'User #'.(int) $this->user_id);
        }

        $this->loadMissing('site');

        return (string) ($this->site?->domain ?? 'Site #'.(int) $this->site_id);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function resolveAddonDefaults(int $serviceId): array
    {
        $service = Service::query()->find($serviceId);
        if ($service === null) {
            return [];
        }

        $providerNamespace = $service->addon_namespace;
        $settingsClass = str_replace(
            class_basename($providerNamespace),
            'Settings',
            $providerNamespace
        );

        if (class_exists($settingsClass) && method_exists($settingsClass, 'getDefaults')) {
            return (new $settingsClass)->getDefaults();
        }

        return [];
    }
}
