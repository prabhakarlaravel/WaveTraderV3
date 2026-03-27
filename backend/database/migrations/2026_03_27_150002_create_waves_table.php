<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('timeframe', 5);
            $table->string('degree', 30);
            $table->string('wave_number', 10);
            $table->timestampTz('start_time');
            $table->timestampTz('end_time')->nullable();
            $table->decimal('start_price', 18, 8);
            $table->decimal('end_price', 18, 8)->nullable();
            $table->unsignedSmallInteger('health_score')->default(100);
            $table->boolean('alternate')->default(false);
            $table->timestamps();

            $table->index(['symbol_id', 'timeframe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waves');
    }
};
