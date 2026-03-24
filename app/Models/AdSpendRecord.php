<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpendRecord extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'spend' => 'decimal:2',
            'imported_at' => 'datetime',
        ];
    }
}
