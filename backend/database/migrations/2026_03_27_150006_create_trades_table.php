<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('type', 10);
            $table->decimal('entry_price', 18, 8);
            $table->decimal('exit_price', 18, 8)->nullable();
            $table->decimal('quantity', 18, 8);
            $table->decimal('sl', 18, 8)->nullable();
            $table->decimal('tp', 18, 8)->nullable();
            $table->string('status', 20)->default('open');
            $table->decimal('pnl', 18, 8)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['symbol_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
