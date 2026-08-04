<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLineItem extends Model
{
    protected $fillable = [
        'order_id',
        'transaction_id',
        'fallback_identity',
        'item_number',
        'title',
        'custom_label',
        'variation',
        'quantity',
        'unit_price',
        'currency',
        'ebay_raw',
        'shipping_amount',
        'total_amount',
        'shipping_service',
        'tracking_number',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'ebay_raw' => 'array',
    ];
}
