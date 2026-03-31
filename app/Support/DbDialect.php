<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DbDialect
{
    /**
     * Database-agnostic expression to extract 'YYYY-MM' from a timestamp column.
     */
    public static function yearMonthExpr(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "to_char({$column}, 'YYYY-MM')";
        }

        return "strftime('%Y-%m', {$column})";
    }

    /**
     * Database-agnostic expression to calculate days between two timestamps.
     */
    public static function daysDiffExpr(string $column1, string $column2): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "EXTRACT(EPOCH FROM ({$column1} - {$column2})) / 86400";
        }

        return "julianday({$column1}) - julianday({$column2})";
    }
}
