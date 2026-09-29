<?php

namespace App\Models;

use App\Enums\PositionSource;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'oauth_sub',
        'external_id',
        'position',
        'position_id',
        'position_source',
        'position_synced_at',
        'department',
        'faculty',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'position_source' => PositionSource::class,
            'position_synced_at' => 'datetime',
        ];
    }

    /**
     * Named assignedPosition because the legacy free-text `position` column still exists.
     */
    public function assignedPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function workRecords(): HasMany
    {
        return $this->hasMany(WorkRecord::class);
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    public function isHr(): bool
    {
        return $this->role === UserRole::Hr;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isHrOrAdmin(): bool
    {
        return $this->isHr() || $this->isAdmin();
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
