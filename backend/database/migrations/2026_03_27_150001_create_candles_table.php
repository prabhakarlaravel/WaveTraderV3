<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candles', function (Blueprint $table) {
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('timeframe', 5);
            $table->timestampTz('timestamp');
            $table->decimal('open', 18, 8);
            $table->decimal('high', 18, 8);
            $table->decimal('low', 18, 8);
            $table->decimal('close', 18, 8);
            $table->decimal('volume', 24, 8)->default(0);

            $table->unique(['symbol_id', 'timeframe', 'timestamp']);
            $table->index(['symbol_id', 'timeframe', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
