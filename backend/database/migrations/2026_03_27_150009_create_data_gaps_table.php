<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('timeframe', 5);
            $table->timestampTz('gap_start');
            $table->timestampTz('gap_end');
            $table->timestampTz('filled_at')->nullable();
            $table->timestamps();

            $table->index(['symbol_id', 'timeframe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_gaps');
    }
};
