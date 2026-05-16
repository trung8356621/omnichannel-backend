<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prompt extends Model
{
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function promptParts(): HasMany
    {
        return $this->hasMany(PromptPart::class);
    }

    public function promptResults(): HasMany
    {
        return $this->hasMany(PromptResult::class);
    }
}
