<?php

namespace App\Enums;

enum HeaterGeneration: string
{
    case Original = 'original';
    case Performance = 'performance';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Original',
            self::Performance => 'Performance',
        };
    }
}
