<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use SoftDeletes;

    const ROLE_ADMIN = 'admin';
    const ROLE_OWNER = 'owner';
    const ROLE_STAFF = 'staff';
    const SEO_ROLE_MANAGER = 'manager';
    const SEO_ROLE_PLANNER = 'planner';
    const SEO_ROLE_CONTENT_MANAGER = 'content_manager';

    const STATUS_NORMAL = 'normal';
    const STATUS_BLOCK = 'block';
    const STATUS_PENDING = 'pending';

    protected $fillable = ['parent_id', 'role', 'seo_role', 'status', 'name', 'email', 'password'];

    /**
     * Luôn dùng DB chính — tránh bị gán connection addon khi eager-load từ model `omi_seo_ai`.
     */
    public function getConnectionName(): ?string
    {
        return (string) config('database.default');
    }

    public function isStaff()
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function staffs()
    {
        return $this->hasMany(User::class, 'parent_id');
    }
}
