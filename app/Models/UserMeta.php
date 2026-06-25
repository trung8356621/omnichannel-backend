<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMeta extends Model
{
    use UsesCoreDatabaseConnection;

    protected $table = 'user_meta';

    protected $fillable = ['user_id', 'meta_key', 'meta_value'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
