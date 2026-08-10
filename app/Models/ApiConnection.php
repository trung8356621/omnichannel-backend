<?php

declare(strict_types=1);

namespace App\Models;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiConnection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_global' => 'boolean',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seoAiModels(): HasMany
    {
        return $this->hasMany(SeoAiModel::class, 'api_connection_id');
    }
}
