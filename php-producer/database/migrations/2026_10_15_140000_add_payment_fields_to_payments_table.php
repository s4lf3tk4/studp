<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('confirmation_url', 512)->nullable()->after('provider');
            $table->string('receipt_email', 128)->nullable()->after('confirmation_url');
            $table->json('notification')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['confirmation_url', 'receipt_email', 'notification']);
        });
    }
};