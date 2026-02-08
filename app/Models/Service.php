<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'slug', 'addon_namespace', 'db_connection', 'is_active', 'config'];

    protected $casts = ['config' => 'array'];

    public function plans()
    {
        return $this->hasMany(ServicePlan::class);
    }
}
