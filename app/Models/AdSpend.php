<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpend extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spend' => 'decimal:2',
            'conversions' => 'decimal:2',
            'conversions_value' => 'decimal:2',
        ];
    }
}
