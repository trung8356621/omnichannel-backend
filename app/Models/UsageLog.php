<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageLog extends Model
{
    protected $fillable = ['subscription_id', 'metric_key', 'current_usage', 'limit_value'];

    // Kiểm tra xem đã vượt hạn mức chưa
    public function isExceeded()
    {
        return $this->current_usage >= $this->limit_value;
    }
}
