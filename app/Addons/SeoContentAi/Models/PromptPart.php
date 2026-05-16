<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptPart extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }
}
