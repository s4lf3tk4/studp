<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16)->default('max');
            $table->unsignedBigInteger('external_user_id')->nullable();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('message_mid')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('file_path')->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->string('status', 32)->default('new');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pages');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('RUB');
            $table->string('status', 16)->default('pending');
            $table->string('provider', 32)->default('stub');
            $table->string('provider_payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('RUB');
            $table->timestamp('issued_at');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('documents');
    }
};