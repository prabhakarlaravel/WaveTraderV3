<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('timeframe', 5);
            $table->date('from_date');
            $table->date('to_date');
            $table->string('mode', 20);
            $table->jsonb('config')->nullable();
            $table->jsonb('results_json')->nullable();
            $table->timestamps();

            $table->index('symbol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtests');
    }
};
