<?php

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected $fillable = ['external_id', 'code', 'name', 'is_active', 'synced_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function structures(): HasMany
    {
        return $this->hasMany(WorkloadStructure::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isFromOracle(): bool
    {
        return $this->external_id !== null;
    }

    public function label(): string
    {
        return $this->code.' '.$this->name;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
