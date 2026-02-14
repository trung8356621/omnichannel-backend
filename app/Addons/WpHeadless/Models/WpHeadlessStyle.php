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
        'parent_id',
        'post_type',
        'style_type',
        'name',
        'url',
        'content',
        'sort_order',
        'external',
    ];

    protected $casts = [
        'parent_id'  => 'integer',
        'sort_order' => 'integer',
        'external'   => 'boolean',
    ];

    public function wpHeadlessSite()
    {
        return $this->belongsTo(WpHeadlessSite::class, 'site_id', 'id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }
}
