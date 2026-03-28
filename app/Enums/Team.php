<?php

namespace App\Enums;

enum Team: string
{
    case Leadership = 'leadership';
    case Brand = 'brand';
    case CustomerSuccess = 'customer_success';
    case RAndD = 'r_and_d';
    case Production = 'production';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Leadership => 'Leadership',
            self::Brand => 'Brand',
            self::CustomerSuccess => 'Customer Success',
            self::RAndD => 'R&D',
            self::Production => 'Production',
            self::Finance => 'Finance',
        };
    }
}
