<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // С кем сейчас общается пользователь: с ботом или с живым оператором
        Schema::create('chat_states', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16);
            $table->unsignedBigInteger('external_user_id');
            $table->string('name')->nullable();          // имя из VK, для панели
            $table->string('mode', 16)->default('bot');  // bot | operator
            $table->timestamps();

            $table->unique(['platform', 'external_user_id']);
            $table->index(['platform', 'mode']);         // «кто ждёт оператора»
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_states');
    }
};