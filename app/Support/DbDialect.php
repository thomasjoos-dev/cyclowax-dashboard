<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DbDialect
{
    /**
     * Extract 'YYYY-MM' from a timestamp column.
     */
    public static function yearMonthExpr(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    /**
     * Extract the month number (1-12) as integer from a timestamp column.
     */
    public static function monthExpr(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "EXTRACT(MONTH FROM {$column})::INTEGER",
            'mysql', 'mariadb' => "MONTH({$column})",
            default => "CAST(strftime('%m', {$column}) AS INTEGER)",
        };
    }

    /**
     * Extract the 4-digit year from a timestamp column.
     */
    public static function yearExpr(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "EXTRACT(YEAR FROM {$column})::TEXT",
            'mysql', 'mariadb' => "YEAR({$column})",
            default => "strftime('%Y', {$column})",
        };
    }

    /**
     * Extract 'YYYY-WW' (ISO year-week) from a timestamp column.
     */
    public static function yearWeekExpr(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "to_char({$column}, 'IYYY-IW')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%v')",
            default => "strftime('%Y-%W', {$column})",
        };
    }

    /**
     * Extract week number from a timestamp column.
     */
    public static function weekExpr(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "EXTRACT(WEEK FROM {$column})::TEXT",
            'mysql', 'mariadb' => "LPAD(WEEK({$column}, 3), 2, '0')",
            default => "strftime('%W', {$column})",
        };
    }

    /**
     * Calculate days between two timestamps.
     */
    public static function daysDiffExpr(string $column1, string $column2): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "EXTRACT(EPOCH FROM ({$column1} - {$column2})) / 86400",
            'mysql', 'mariadb' => "DATEDIFF({$column1}, {$column2})",
            default => "julianday({$column1}) - julianday({$column2})",
        };
    }

    /**
     * Calculate integer days since a column value until a given date string.
     */
    public static function daysSinceExpr(string $column, string $dateString): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "EXTRACT(EPOCH FROM ('{$dateString}'::timestamp - {$column})) / 86400",
            'mysql', 'mariadb' => "DATEDIFF('{$dateString}', {$column})",
            default => "CAST(julianday('{$dateString}') - julianday({$column}) AS INTEGER)",
        };
    }
}
