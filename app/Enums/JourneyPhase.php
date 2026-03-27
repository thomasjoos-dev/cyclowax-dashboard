<?php

namespace App\Enums;

enum JourneyPhase: string
{
    case GettingStarted = 'getting_started';
    case WaxRoutineCycle = 'wax_routine_cycle';

    public function label(): string
    {
        return match ($this) {
            self::GettingStarted => 'Getting Started',
            self::WaxRoutineCycle => 'Wax, Ride, Win, Repeat',
        };
    }
}
