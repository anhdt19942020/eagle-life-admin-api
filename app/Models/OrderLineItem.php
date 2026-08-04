<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLineItem extends Model
{
    protected $fillable = ['order_id', 'transaction_id', 'fallback_identity', 'item_number', 'title', 'custom_label', 'variation', 'quantity', 'unit_price', 'currency'];
    protected $casts = ['unit_price' => 'decimal:2'];
}
