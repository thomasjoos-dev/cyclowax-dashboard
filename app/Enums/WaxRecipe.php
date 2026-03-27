<?php

namespace App\Enums;

enum WaxRecipe: string
{
    case Core = 'core';
    case Performance = 'performance';
    case Race = 'race';

    public function label(): string
    {
        return match ($this) {
            self::Core => 'Core',
            self::Performance => 'Performance',
            self::Race => 'Race',
        };
    }
}
