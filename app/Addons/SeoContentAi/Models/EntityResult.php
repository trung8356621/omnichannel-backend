<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityResult extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'request_payload'  => 'json',
        'response_payload' => 'json',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function promptResult(): BelongsTo
    {
        return $this->belongsTo(PromptResult::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
