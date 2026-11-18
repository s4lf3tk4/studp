<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Формат => часть имени колонки */
    private const FORMATS = ['a4', 'a3', 'a2', 'a1', 'a0'];

    /** Режим печати => префикс колонки */
    private const MODES = ['bw', 'color'];

    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            // Цены в копейках: 230 = 2,30 ₽. Админ выставляет их в панели.
            // 0 — этот формат в корпусе не печатают, бот его не предложит.
            foreach (self::MODES as $mode) {
                foreach (self::FORMATS as $format) {
                    $table->unsignedInteger("price_{$mode}_{$format}_kopecks")->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            foreach (self::MODES as $mode) {
                foreach (self::FORMATS as $format) {
                    $table->dropColumn("price_{$mode}_{$format}_kopecks");
                }
            }
        });
    }
};