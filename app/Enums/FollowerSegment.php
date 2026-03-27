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
}
