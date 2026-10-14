<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            // Цена за страницу в копейках: 200 = 2 ₽. 0 — цена не задана, бот её не показывает
            $table->unsignedInteger('price_per_page_kopecks')->default(200)->after('room');
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->dropColumn('price_per_page_kopecks');
        });
    }
};