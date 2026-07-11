<?php

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;

class SiteMeta extends Model
{
    use UsesCoreDatabaseConnection;

    protected $table = 'site_meta';

    protected $fillable = ['site_id', 'meta_key', 'meta_value'];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
