<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'ebay_order_id',
        'buyer_id',
        'seller_id',
        'ebay_created_at',
        'ebay_order_number',
    ];

    protected $casts = [
        'ebay_created_at' => 'datetime',
        'printify_created_at' => 'datetime',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function fulfillmentAddress(): HasOne
    {
        return $this->hasOne(OrderFulfillmentAddress::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(OrderLineItem::class);
    }

    public function importBatchItems(): HasMany
    {
        return $this->hasMany(OrderImportBatchItem::class);
    }

    public function printifyOrder(): HasOne
    {
        return $this->hasOne(PrintifyOrder::class);
    }
}
