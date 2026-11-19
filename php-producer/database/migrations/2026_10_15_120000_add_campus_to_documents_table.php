<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('campus', 32)->nullable()->after('file_path');
            // Режим печати: bw — ЧБ, color — цветной. NULL — документ старше выбора режима
            $table->string('print_mode', 8)->nullable()->after('pages');
            // Номера страниц, которые печатаются в противоположном режиме: [3, 7]
            $table->json('special_pages')->nullable()->after('print_mode');
            // Форматы страниц: {"A4": 10, "A3": 2} — по ним считается стоимость
            $table->json('pages_by_format')->nullable()->after('special_pages');
            $table->index(['status', 'created_at']);   // prune ходит именно по этим полям
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['campus', 'print_mode', 'special_pages', 'pages_by_format']);
        });
    }
};