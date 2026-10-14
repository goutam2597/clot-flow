<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Generates sequential, gapless-ish document numbers with a configurable prefix.
 * Relies on the database to remain reliable under concurrent requests by
 * incrementing a counter row inside the caller's transaction.
 */
class NumberGenerator
{
    /**
     * Produce the next sequential number for a prefix.
     * Format: {PREFIX}-{YEAR}{MONTH}-{PADDED}  e.g. INV-202410-0001
     */
    public static function next(string $prefix, int $pad = 5): string
    {
        $period = now()->format('Ym');

        $row = DB::table('number_counters')->where('key', $prefix)->lockForUpdate()->first();

        if (! $row) {
            DB::table('number_counters')->insert([
                'key' => $prefix,
                'current' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $current = 0;
        } else {
            $current = (int) $row->current;
        }

        $next = $current + 1;

        DB::table('number_counters')->where('key', $prefix)->update([
            'current' => $next,
            'updated_at' => now(),
        ]);

        return sprintf('%s-%s-%0' . $pad . 'd', $prefix, $period, $next);
    }
}
