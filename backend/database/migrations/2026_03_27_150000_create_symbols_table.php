<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->string('exchange', 20);
            $table->string('ticker', 40);
            $table->string('name');
            $table->string('type', 20)->default('equity');
            $table->string('session', 40)->nullable();
            $table->string('timezone', 40)->default('Asia/Kolkata');
            $table->decimal('lot_size', 12, 4)->default(1);
            $table->decimal('tick_size', 12, 6)->default(0.01);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['exchange', 'ticker']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbols');
    }
};
