<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Models;

use Illuminate\Database\Eloquent\Model;

class WpHeadlessSite extends Model
{
    protected $connection = 'wp_headless';

    protected $table = 'wp_headless_sites';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'type', 'public_url', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function styles()
    {
        return $this->hasMany(WpHeadlessStyle::class, 'site_id', 'id');
    }

    public function templates()
    {
        return $this->hasMany(WpHeadlessTemplate::class, 'site_id', 'id');
    }
}
