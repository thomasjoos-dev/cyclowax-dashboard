<?php

namespace App\Enums;

enum FollowerSegment: string
{
    case New = 'new';
    case Engaged = 'engaged';
    case HighPotential = 'high_potential';
    case HotLead = 'hot_lead';
    case Fading = 'fading';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Engaged => 'Engaged',
            self::HighPotential => 'High Potential',
            self::HotLead => 'Hot Lead',
            self::Fading => 'Fading',
            self::Inactive => 'Inactive',
        };
    }

    public function isDisengaged(): bool
    {
        return in_array($this, [self::Fading, self::Inactive]);
    }
}
