<?php

namespace App\Models;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Concerns\UsesCoreDatabaseConnection;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;
    use SoftDeletes;
    use UsesCoreDatabaseConnection;

    const ROLE_ADMIN = 'admin';

    const ROLE_OWNER = 'owner';

    /** Organizational manager under an Owner (not seo_role). */
    const ROLE_MANAGER = 'manager';

    const ROLE_STAFF = 'staff';

    const SEO_ROLE_MANAGER = 'manager';

    const SEO_ROLE_PLANNER = 'planner';

    const SEO_ROLE_CONTENT_MANAGER = 'content_manager';

    const STATUS_NORMAL = 'normal';

    const STATUS_BLOCK = 'block';

    const STATUS_PENDING = 'pending';

    /**
     * parent_id = Owner FK (legacy column name; semantics = owner_id).
     * manager_id = Manager FK for Staff.
     */
    protected $fillable = [
        'parent_id',
        'manager_id',
        'role',
        'seo_role',
        'status',
        'name',
        'email',
        'password',
    ];

    protected $appends = ['display_name'];

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ((string) ($this->status ?? '') === self::STATUS_BLOCK) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => in_array((string) $this->role, [self::ROLE_ADMIN, self::ROLE_OWNER], true)
                || SeoAccessControl::canAccessAdminAutomationPanel($this),
            'tools' => (string) ($this->status ?? '') !== self::STATUS_BLOCK,
            'seo' => SeoAccessControl::canAccessSeoPanel($this),
            default => false,
        };
    }

    /**
     * Owner of this Manager/Staff (column parent_id).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * @deprecated Use owner() — parent_id means Owner.
     */
    public function parent(): BelongsTo
    {
        return $this->owner();
    }

    /**
     * Manager of this Staff.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Managers belonging to this Owner.
     */
    public function managers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id')->where('role', self::ROLE_MANAGER);
    }

    /**
     * Staff belonging to this Manager (manager_id).
     */
    public function staffMembers(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id')->where('role', self::ROLE_STAFF);
    }

    /**
     * Staff under this Owner with no Manager assigned.
     */
    public function directStaffMembers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id')
            ->where('role', self::ROLE_STAFF)
            ->whereNull('manager_id');
    }

    /**
     * All users with parent_id = this user (managers + staff). Legacy alias.
     */
    public function staffs(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /**
     * Owner account id for scoping (Owner self, or parent_id for Manager/Staff).
     */
    public function accountOwnerId(): ?int
    {
        if (in_array((string) $this->role, [self::ROLE_STAFF, self::ROLE_MANAGER], true)
            && (int) $this->parent_id > 0
        ) {
            return (int) $this->parent_id;
        }

        if ((string) $this->role === self::ROLE_OWNER) {
            return (int) $this->id;
        }

        return null;
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

    /**
     * Lịch sử duyệt bài SEO (cross-DB query — bảng trên connection omi_seo_ai).
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticleReview>
     */
    public function articleReviews()
    {
        return \Omnichannel\Addons\Content\Models\SeoArticleReview::query()
            ->where('reviewer_id', (int) $this->id);
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
