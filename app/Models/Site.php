<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'subscription_id', 'domain', 'url', 'status'];

    public function metas()
    {
        return $this->hasMany(SiteMeta::class);
    }

    public function taskJobs()
    {
        return $this->hasMany(TaskJob::class);
    }

    // Helper để lấy nhanh một giá trị meta
    public function getMeta($key, $default = null)
    {
        $meta = $this->metas()->where('meta_key', $key)->first();
        return $meta ? $meta->meta_value : $default;
    }
}
