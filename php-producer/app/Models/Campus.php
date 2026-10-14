<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Campus extends Model
{
    protected $fillable = ['code', 'title', 'address', 'room', 'is_active', 'price_per_page_kopecks'];

    protected function casts(): array
    {
        return ['is_active' => 'bool', 'price_per_page_kopecks' => 'int'];
    }

    /** @return HasMany<CampusDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(CampusDay::class)->orderBy('weekday');
    }
        /** Корпус существует и включён */
    public static function isActive(string $code): bool
    {
        return self::query()->where('code', $code)->where('is_active', true)->exists();
    }
}