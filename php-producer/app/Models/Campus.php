<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Campus extends Model
{
    /** Цены за страницу: режим печати × формат бумаги. Порядок важен для панели. */
    public const PRICE_COLUMNS = [
        'price_bw_a4_kopecks', 'price_bw_a3_kopecks', 'price_bw_a2_kopecks',
        'price_bw_a1_kopecks', 'price_bw_a0_kopecks',
        'price_color_a4_kopecks', 'price_color_a3_kopecks', 'price_color_a2_kopecks',
        'price_color_a1_kopecks', 'price_color_a0_kopecks',
    ];

    protected $fillable = [
        'code', 'title', 'address', 'room', 'is_active',
        'price_bw_a4_kopecks', 'price_bw_a3_kopecks', 'price_bw_a2_kopecks',
        'price_bw_a1_kopecks', 'price_bw_a0_kopecks',
        'price_color_a4_kopecks', 'price_color_a3_kopecks', 'price_color_a2_kopecks',
        'price_color_a1_kopecks', 'price_color_a0_kopecks',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'price_bw_a4_kopecks' => 'int', 'price_bw_a3_kopecks' => 'int',
            'price_bw_a2_kopecks' => 'int', 'price_bw_a1_kopecks' => 'int',
            'price_bw_a0_kopecks' => 'int',
            'price_color_a4_kopecks' => 'int', 'price_color_a3_kopecks' => 'int',
            'price_color_a2_kopecks' => 'int', 'price_color_a1_kopecks' => 'int',
            'price_color_a0_kopecks' => 'int',
        ];
    }

    /** @return HasMany<CampusDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(CampusDay::class)->orderBy('weekday');
    }
}