<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Один день недели корпуса: работает или нет, часы работы и обед */
final class CampusDay extends Model
{
    protected $fillable = [
        'campus_id', 'weekday', 'is_working',
        'opens_at', 'closes_at', 'lunch_starts_at', 'lunch_ends_at',
    ];

    protected function casts(): array
    {
        return ['weekday' => 'int', 'is_working' => 'bool'];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}