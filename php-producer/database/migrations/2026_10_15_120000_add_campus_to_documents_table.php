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
            $table->index(['status', 'created_at']);   // prune ходит именно по этим полям
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn('campus');
        });
    }
};