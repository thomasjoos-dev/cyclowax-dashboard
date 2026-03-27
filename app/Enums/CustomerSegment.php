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
}
