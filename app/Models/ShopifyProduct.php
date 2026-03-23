<?php

namespace App\Models;

use Database\Factories\ShopifyProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProduct extends Model
{
    /** @use HasFactory<ShopifyProductFactory> */
    use HasFactory;

    protected $guarded = [];
}
