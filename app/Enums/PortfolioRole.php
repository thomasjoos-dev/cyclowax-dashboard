<?php

namespace App\Enums;

enum PortfolioRole: string
{
    case Acquisition = 'acquisition';
    case RetentionDriver = 'retention_driver';
    case MarginProtector = 'margin_protector';
    case LoyaltyBuilder = 'loyalty_builder';

    public function label(): string
    {
        return match ($this) {
            self::Acquisition => 'Acquisition',
            self::RetentionDriver => 'Retention Driver',
            self::MarginProtector => 'Margin Protector',
            self::LoyaltyBuilder => 'Loyalty Builder',
        };
    }
}
