<?php

namespace App\Enums;

enum CustomerSegment: string
{
    case Champion = 'champion';
    case AtRisk = 'at_risk';
    case Rising = 'rising';
    case Loyal = 'loyal';
    case Hunters = 'hunters';
    case PromisingFirst = 'promising_first';
    case OneTimer = 'one_timer';
    case NewCustomer = 'new_customer';

    public function label(): string
    {
        return match ($this) {
            self::Champion => 'Champion',
            self::AtRisk => 'At Risk',
            self::Rising => 'Rising',
            self::Loyal => 'Loyal',
            self::Hunters => 'Bargain Hunters',
            self::PromisingFirst => 'Promising First',
            self::OneTimer => 'One Timer',
            self::NewCustomer => 'New Customer',
        };
    }

    public function isAtRisk(): bool
    {
        return in_array($this, [self::AtRisk, self::OneTimer, self::Hunters]);
    }
}
