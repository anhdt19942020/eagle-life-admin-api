<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'ebay_order_id',
        'buyer_id',
        'seller_id',
        'ebay_created_at',
        'printify_created_at',
        'printify_order_id',
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
}
