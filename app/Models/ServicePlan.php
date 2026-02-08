<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServicePlan extends Model
{
    protected $fillable = ['service_id', 'name', 'price', 'duration_days', 'limits'];

    protected $casts = ['limits' => 'array'];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
