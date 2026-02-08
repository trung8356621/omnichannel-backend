<?php
namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\File;

class AddonManager
{
    public static function discover()
    {
        $path = app_path('Addons');
        if (!File::exists($path))
            File::makeDirectory($path);

        $directories = File::directories($path);
        $discovered = [];

        foreach ($directories as $dir) {
            $jsonPath = $dir . '/addon.json';
            if (File::exists($jsonPath)) {
                $meta = json_decode(File::get($jsonPath), true);

                // Đồng bộ vào DB
                Service::updateOrCreate(
                    ['slug' => $meta['slug']],
                    [
                        'name' => $meta['name'],
                        'addon_namespace' => $meta['provider'],
                        'config' => $meta
                    ]
                );
                $discovered[] = $meta['slug'];
            }
        }
        return $discovered;
    }
}
