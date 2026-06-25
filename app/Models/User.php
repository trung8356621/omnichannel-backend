<?php

namespace App\Models;

use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;
    use SoftDeletes;
    use UsesCoreDatabaseConnection;

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

    protected $appends = ['display_name'];

    public function isStaff()
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ((string) ($this->status ?? '') === self::STATUS_BLOCK) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => in_array((string) $this->role, [self::ROLE_ADMIN, self::ROLE_OWNER], true),
            'tools' => (string) ($this->status ?? '') !== self::STATUS_BLOCK,
            'seo' => SeoAccessControl::canAccessSeoPanel($this),
            default => false,
        };
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function staffs()
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function sites()
    {
        return $this->hasMany(Site::class);
    }

    public function seoConnections()
    {
        return $this->belongsToMany(
            SeoDatabaseConnection::class,
            'seo_connection_users',
            'user_id',
            'connection_id',
        )->withTimestamps();
    }

    public function meta(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserMeta::class, 'user_id');
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        $row = $this->meta()->where('meta_key', $key)->first();

        return $row?->meta_value ?? $default;
    }

    public function setMeta(string $key, mixed $value): static
    {
        $this->meta()->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => $value],
        );

        return $this;
    }

    public function getDisplayNameAttribute(): string
    {
        $nickname = $this->getMeta('nickname');

        return $nickname ?: $this->name;
    }
}