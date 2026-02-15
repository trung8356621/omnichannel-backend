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
        'parent_id',
        'type',
        'global',
        'template',
        'classes',
        'body_class',
    ];

    protected $casts = [
        'parent_id'  => 'integer',
        'global'     => 'boolean',
        'classes'    => 'array',
        'body_class' => 'array',
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
