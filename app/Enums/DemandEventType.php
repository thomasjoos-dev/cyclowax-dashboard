<?php

namespace App\Enums;

enum DemandEventType: string
{
    case PromoCampaign = 'promo_campaign';
    case ProductLaunch = 'product_launch';

    public function label(): string
    {
        return match ($this) {
            self::PromoCampaign => 'Promo Campaign',
            self::ProductLaunch => 'Product Launch',
        };
    }
}
