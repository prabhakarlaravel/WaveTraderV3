<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->string('engine', 30)->nullable()->after('tp');
            $table->string('timeframe', 5)->nullable()->after('engine');
            $table->string('wave_position', 10)->nullable()->after('timeframe');
            $table->unsignedSmallInteger('confluence_score')->default(0)->after('wave_position');
            $table->text('notes')->nullable()->after('pnl');
            $table->jsonb('tags')->nullable()->after('notes');
            $table->boolean('auto_trade')->default(false)->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn(['engine', 'timeframe', 'wave_position', 'confluence_score', 'notes', 'tags', 'auto_trade']);
        });
    }
};
