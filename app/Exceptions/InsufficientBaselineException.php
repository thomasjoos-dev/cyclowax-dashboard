<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBaselineException extends RuntimeException
{
    public static function noQ1Data(int $year): self
    {
        return new self("No Q1 actuals available for {$year}. At least one month of Q1 data is required to generate a forecast.");
    }
}
