<?php

namespace App\Enums;

enum ProductCategory: string
{
    case Chain = 'chain';
    case ChainConsumable = 'chain_consumable';
    case ChainTool = 'chain_tool';
    case WaxTablet = 'wax_tablet';
    case PocketWax = 'pocket_wax';
    case Heater = 'heater';
    case HeaterAccessory = 'heater_accessory';
    case Accessory = 'accessory';
    case Cleaning = 'cleaning';
    case MultiTool = 'multi_tool';
    case StarterKit = 'starter_kit';
    case WaxKit = 'wax_kit';
    case Bundle = 'bundle';
    case GiftCard = 'gift_card';
    case Promotional = 'promotional';

    public function label(): string
    {
        return match ($this) {
            self::Chain => 'Prewaxed Chain',
            self::ChainConsumable => 'Quick Link',
            self::ChainTool => 'Chain Tool',
            self::WaxTablet => 'Wax Tablet',
            self::PocketWax => 'Pocket Wax',
            self::Heater => 'Heater',
            self::HeaterAccessory => 'Heater Accessory',
            self::Accessory => 'Accessory',
            self::Cleaning => 'Cleaning',
            self::MultiTool => 'Multi-Tool',
            self::StarterKit => 'Starter Kit',
            self::WaxKit => 'Wax Kit',
            self::Bundle => 'Bundle',
            self::GiftCard => 'Gift Card',
            self::Promotional => 'Promotional',
        };
    }

    public function forecastGroup(): ?ForecastGroup
    {
        return match ($this) {
            self::WaxTablet, self::PocketWax => ForecastGroup::RideActivity,
            self::StarterKit, self::WaxKit => ForecastGroup::GettingStarted,
            self::Chain, self::ChainConsumable, self::ChainTool => ForecastGroup::ChainWear,
            self::Heater, self::HeaterAccessory, self::Cleaning, self::MultiTool, self::Accessory => ForecastGroup::Companion,
            self::Bundle => ForecastGroup::GettingStarted,
            self::GiftCard, self::Promotional => null,
        };
    }

    public function ecosystem(): string
    {
        return match ($this) {
            self::Chain, self::ChainConsumable, self::ChainTool => 'chain',
            self::WaxTablet, self::PocketWax => 'wax',
            self::Heater, self::HeaterAccessory => 'heater',
            self::Accessory, self::MultiTool => 'accessory',
            self::Cleaning => 'cleaning',
            self::StarterKit, self::WaxKit, self::Bundle => 'kit',
            self::GiftCard, self::Promotional => 'other',
        };
    }
}
