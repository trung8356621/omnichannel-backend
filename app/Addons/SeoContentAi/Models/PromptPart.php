<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptPart extends Model
{
    protected $connection = 'omi_seo_ai';

    /** @var string Bảng vật lý là `prompt_parts` (không phải `seo_prompt_parts`). */
    protected $table = 'prompt_parts';

    protected $fillable = [
        'prompt_id',
        'position',
        'role',
        'name',
        'content',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }
}
