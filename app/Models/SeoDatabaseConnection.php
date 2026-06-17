<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class SeoDatabaseConnection extends Model
{
    protected $fillable = [
        'name',
        'hash_id',
        'type',
        'host',
        'port',
        'database',
        'username',
        'password',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SeoDatabaseConnection $connection): void {
            if (blank($connection->hash_id)) {
                $connection->hash_id = Str::random(32);
            }

            if (blank($connection->type)) {
                $connection->type = (string) config('seo-content-ai.default_connection_type', 'manual');
            }
        });

        static::created(function (SeoDatabaseConnection $connection): void {
            if ($connection->type !== 'auto' || filled($connection->database)) {
                return;
            }

            $connection->forceFill([
                'database' => 'omi_seo_ai_auto_'.$connection->getKey(),
            ])->saveQuietly();
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'seo_connection_users', 'connection_id', 'user_id')
            ->withTimestamps();
    }

    public function isManual(): bool
    {
        return $this->type === 'manual';
    }

    public function isAuto(): bool
    {
        return $this->type === 'auto';
    }

    public function panelUrl(): string
    {
        return '/seo/'.$this->hash_id;
    }
}
