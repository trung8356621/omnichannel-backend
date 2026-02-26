<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Models;

use Illuminate\Database\Eloquent\Model;

class WpHeadlessStyleOptimized extends Model
{
    protected $connection = 'wp_headless';

    protected $table = 'wp_headless_styles_optimized';

    protected $fillable = [
        'site_id',
        'post_type',
        'chunk_index',
        'path',
        'size',
    ];

    /** URL public để WordPress enqueue trực tiếp. */
    public function getPublicUrlAttribute(): ?string
    {
        $path = $this->attributes['path'] ?? null;
        return $path !== null && $path !== '' ? asset($path) : null;
    }

    protected $casts = [
        'chunk_index' => 'integer',
        'size'        => 'integer',
    ];

    public function wpHeadlessSite()
    {
        return $this->belongsTo(WpHeadlessSite::class, 'site_id', 'id');
    }
}
