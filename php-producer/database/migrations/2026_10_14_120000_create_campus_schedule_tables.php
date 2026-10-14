<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();        // campus1..campus4 — как в BotCommand::CAMPUSES
            $table->string('title', 128);                // «Корпус 1»
            $table->string('address', 255)->nullable();  // фактический адрес
            $table->string('room', 32)->nullable();      // кабинет приёма — настраивается отдельно
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Каждый день недели — отдельная строка со своими настройками
        Schema::create('campus_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');        // ISO-8601: 1 = Пн … 7 = Вс
            $table->boolean('is_working')->default(false); // работает ли в этот день
            $table->time('opens_at')->nullable();          // начало работы
            $table->time('closes_at')->nullable();         // конец работы
            $table->time('lunch_starts_at')->nullable();   // начало обеда
            $table->time('lunch_ends_at')->nullable();     // конец обеда
            $table->timestamps();

            $table->unique(['campus_id', 'weekday']);      // у корпуса ровно одна строка на день
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_days');
        Schema::dropIfExists('campuses');
    }
};