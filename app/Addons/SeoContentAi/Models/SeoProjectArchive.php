<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProjectArchive extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_archives';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'archived_by' => 'integer',
        'articles_count' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function archivedByUser(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'archived_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SeoProjectArchiveItem::class, 'seo_project_archive_id');
    }
}
