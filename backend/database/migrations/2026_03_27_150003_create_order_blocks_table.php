<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('timeframe', 5);
            $table->string('type', 10);
            $table->decimal('high', 18, 8);
            $table->decimal('low', 18, 8);
            $table->timestampTz('formed_at');
            $table->string('status', 20)->default('fresh');
            $table->unsignedSmallInteger('strength')->default(50);
            $table->timestamps();

            $table->index(['symbol_id', 'timeframe', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_blocks');
    }
};
