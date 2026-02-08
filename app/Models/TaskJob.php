<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskJob extends Model
{
    protected $fillable = [
        'site_id',
        'task_type',
        'status',
        'progress_percent',
        'error_log',
        'started_at',
        'finished_at'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
