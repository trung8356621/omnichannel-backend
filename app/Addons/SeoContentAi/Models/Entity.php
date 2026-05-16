<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'credential_data' => 'encrypted:json',
        'parsed_data'     => 'json',
        'keywords'        => 'array',
        'settings'        => 'array',
        'is_active'       => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function promptResults(): HasMany
    {
        return $this->hasMany(PromptResult::class);
    }

    public function entityResults(): HasMany
    {
        return $this->hasMany(EntityResult::class);
    }
}
