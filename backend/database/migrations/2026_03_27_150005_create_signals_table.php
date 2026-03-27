<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('timeframe', 5);
            $table->string('engine', 30);
            $table->string('direction', 10);
            $table->decimal('entry', 18, 8);
            $table->decimal('sl', 18, 8)->nullable();
            $table->decimal('tp', 18, 8)->nullable();
            $table->unsignedSmallInteger('confluence_score')->default(0);
            $table->timestampTz('candle_timestamp')->nullable();
            $table->timestamps();

            $table->index(['symbol_id', 'timeframe', 'engine']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
