<?php

namespace App\Enums;

enum LifecycleStage: string
{
    case Follower = 'follower';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Follower => 'Volger',
            self::Customer => 'Klant',
        };
    }
}
