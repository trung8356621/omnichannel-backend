<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Addon\AddonDiscovery;
use App\Core\Addon\AddonRegistry;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

/**
 * Discovers addons via Client Core AddonDiscovery and syncs Service rows.
 * Does not hard-code business addon class names.
 */
class AddonManager
{
    /**
     * @return list<string> Discovered slugs
     */
    public static function discover(): array
    {
        /** @var AddonDiscovery $discovery */
        $discovery = app(AddonDiscovery::class);
        /** @var AddonRegistry $registry */
        $registry = app(AddonRegistry::class);

        $roots = config('addons.discovery_roots', ['app/Addons', 'addons']);
        $skip = config('addons.skip_slugs', []);
        $manifests = $discovery->discover($roots, $skip);
        $registry->replaceAll($manifests);

        if (! Schema::hasTable('services')) {
            return array_map(static fn ($m) => $m->slug, $manifests);
        }

        $discovered = [];
        foreach ($manifests as $manifest) {
            Service::updateOrCreate(
                ['slug' => $manifest->slug],
                [
                    'name' => $manifest->name,
                    'addon_namespace' => $manifest->provider,
                    'config' => $manifest->raw,
                ]
            );
            $discovered[] = $manifest->slug;
        }

        return $discovered;
    }
}
