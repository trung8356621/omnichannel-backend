<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Models;

use Illuminate\Database\Eloquent\Model;

class WpHeadlessTemplate extends Model
{
    protected $connection = 'wp_headless';

    protected $table = 'wp_headless_templates';

    protected $fillable = [
        'site_id',
        'type',
        'template',
        'classes',
        'styles',
    ];

    protected $casts = [
        'classes' => 'array',
        'styles'  => 'array',
    ];

    public function wpHeadlessSite()
    {
        return $this->belongsTo(WpHeadlessSite::class, 'site_id', 'id');
    }
}
