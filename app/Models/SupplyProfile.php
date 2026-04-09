<?php

namespace App\Models;

use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplyProfile extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'product_category',
        'procurement_lead_time_days',
        'assembly_lead_time_days',
        'moq',
        'buffer_days',
        'supplier_name',
        'notes',
        'validated_at',
        'validated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_category' => ProductCategory::class,
            'procurement_lead_time_days' => 'integer',
            'assembly_lead_time_days' => 'integer',
            'moq' => 'integer',
            'buffer_days' => 'integer',
            'validated_at' => 'datetime',
        ];
    }
}
