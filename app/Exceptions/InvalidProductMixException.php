<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidProductMixException extends RuntimeException
{
    /**
     * @param  array<string, string>  $violations
     */
    public static function sharesOutOfRange(array $violations): self
    {
        $details = collect($violations)
            ->map(fn (string $msg, string $field) => "{$field}: {$msg}")
            ->implode('; ');

        return new self("Product mix shares out of valid range: {$details}");
    }

    public static function sumOutOfTolerance(string $shareType, float $sum, float $min = 0.95, float $max = 1.05): self
    {
        $rounded = round($sum, 4);

        return new self("Sum of {$shareType} shares is {$rounded}, expected between {$min} and {$max}");
    }
}
