<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix Zerodha (NSE/BSE/NFO/MCX) candle timestamps.
 *
 * Zerodha KiteConnect returns timestamps in IST (UTC+5:30), but they were
 * stored as-is without conversion — making them appear as UTC in the DB.
 * This migration subtracts 5h30m to convert them to true UTC.
 *
 * Strategy: drop unique constraint → update → remove any duplicates → re-add constraint.
 */
return new class extends Migration
{
    private const IST_INTERVAL = '5 hours 30 minutes';

    public function up(): void
    {
        $zerodhaSymbolIds = DB::table('symbols')
            ->whereIn('exchange', ['NSE', 'BSE', 'NFO', 'MCX', 'ZERODHA'])
            ->pluck('id')
            ->toArray();

        if (empty($zerodhaSymbolIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($zerodhaSymbolIds), '?'));

        // 1. Drop unique constraint to allow the timestamp shift
        DB::statement('ALTER TABLE candles DROP CONSTRAINT IF EXISTS candles_symbol_id_timeframe_timestamp_unique');

        // 2. Shift candle timestamps: IST → UTC (subtract 5h30m)
        DB::statement(
            "UPDATE candles SET timestamp = timestamp - INTERVAL '" . self::IST_INTERVAL . "'
             WHERE symbol_id IN ({$placeholders})",
            $zerodhaSymbolIds
        );

        // 3. Remove duplicates (keep the row with the latest ctid if any overlap)
        DB::statement(
            "DELETE FROM candles a USING candles b
             WHERE a.symbol_id = b.symbol_id
               AND a.timeframe = b.timeframe
               AND a.timestamp = b.timestamp
               AND a.ctid < b.ctid"
        );

        // 4. Re-add unique constraint
        DB::statement(
            'ALTER TABLE candles ADD CONSTRAINT candles_symbol_id_timeframe_timestamp_unique
             UNIQUE (symbol_id, timeframe, timestamp)'
        );

        // 5. Fix engine output tables
        $tables = [
            'waves' => ['start_time', 'end_time'],
            'order_blocks' => ['formed_at'],
            'fvgs' => ['formed_at'],
            'signals' => ['created_at'],
        ];

        foreach ($tables as $table => $columns) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            foreach ($columns as $col) {
                if (! DB::getSchemaBuilder()->hasColumn($table, $col)) {
                    continue;
                }

                DB::statement(
                    "UPDATE {$table} SET {$col} = {$col} - INTERVAL '" . self::IST_INTERVAL . "'
                     WHERE symbol_id IN ({$placeholders})",
                    $zerodhaSymbolIds
                );
            }
        }
    }

    public function down(): void
    {
        $zerodhaSymbolIds = DB::table('symbols')
            ->whereIn('exchange', ['NSE', 'BSE', 'NFO', 'MCX', 'ZERODHA'])
            ->pluck('id')
            ->toArray();

        if (empty($zerodhaSymbolIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($zerodhaSymbolIds), '?'));

        DB::statement('ALTER TABLE candles DROP CONSTRAINT IF EXISTS candles_symbol_id_timeframe_timestamp_unique');

        DB::statement(
            "UPDATE candles SET timestamp = timestamp + INTERVAL '" . self::IST_INTERVAL . "'
             WHERE symbol_id IN ({$placeholders})",
            $zerodhaSymbolIds
        );

        DB::statement(
            "DELETE FROM candles a USING candles b
             WHERE a.symbol_id = b.symbol_id
               AND a.timeframe = b.timeframe
               AND a.timestamp = b.timestamp
               AND a.ctid < b.ctid"
        );

        DB::statement(
            'ALTER TABLE candles ADD CONSTRAINT candles_symbol_id_timeframe_timestamp_unique
             UNIQUE (symbol_id, timeframe, timestamp)'
        );

        $tables = [
            'waves' => ['start_time', 'end_time'],
            'order_blocks' => ['formed_at'],
            'fvgs' => ['formed_at'],
            'signals' => ['created_at'],
        ];

        foreach ($tables as $table => $columns) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            foreach ($columns as $col) {
                if (! DB::getSchemaBuilder()->hasColumn($table, $col)) {
                    continue;
                }

                DB::statement(
                    "UPDATE {$table} SET {$col} = {$col} + INTERVAL '" . self::IST_INTERVAL . "'
                     WHERE symbol_id IN ({$placeholders})",
                    $zerodhaSymbolIds
                );
            }
        }
    }
};
