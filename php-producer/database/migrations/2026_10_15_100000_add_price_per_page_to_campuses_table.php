<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            // Цена за страницу в копейках. 0 — админ ещё не задал цену, бот её не называет
            $table->unsignedInteger('price_per_page_kopecks')->default(0)->after('room');
            // Цена цветной печати. 0 — цветной принтер не настроен, бот этот режим не предлагает
            $table->unsignedInteger('color_price_per_page_kopecks')->default(0)->after('price_per_page_kopecks');
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->dropColumn(['price_per_page_kopecks', 'color_price_per_page_kopecks']);
        });
    }
};