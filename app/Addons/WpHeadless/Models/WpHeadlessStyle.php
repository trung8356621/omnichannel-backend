<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Models;

use Illuminate\Database\Eloquent\Model;

class WpHeadlessStyle extends Model
{
    protected $connection = 'wp_headless';

    protected $table = 'wp_headless_styles';

    protected $fillable = [
        'site_id',
        'post_type',
        'style_type',
        'name',
        'url',
        'content',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function wpHeadlessSite()
    {
        return $this->belongsTo(WpHeadlessSite::class, 'site_id', 'id');
    }
}
