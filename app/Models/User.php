<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use SoftDeletes;

    const ROLE_ADMIN = 'admin';
    const ROLE_OWNER = 'owner';
    const ROLE_STAFF = 'staff';

    const STATUS_NORMAL = 'normal';
    const STATUS_BLOCK = 'block';
    const STATUS_PENDING = 'pending';

    protected $fillable = ['parent_id', 'role', 'status', 'name', 'email', 'password'];

    // Kiểm tra nhân viên
    public function isStaff()
    {
        return $this->role === self::ROLE_STAFF;
    }

    // Lấy chủ sở hữu (nếu là nhân viên)
    public function owner()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    // Lấy danh sách nhân viên (nếu là chủ sở hữu)
    public function staffs()
    {
        return $this->hasMany(User::class, 'parent_id');
    }
}
