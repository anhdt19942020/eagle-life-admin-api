<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'ebay_order_id',
        'ebay_order_number',
        'ebay_export_rows',
        'ebay_buyer_username',
        'ebay_buyer_name',
        'ebay_buyer_email',
        'buyer_id',
        'seller_id',
        'ebay_created_at',
    ];

    protected $casts = [
        'ebay_created_at' => 'datetime',
        'printify_created_at' => 'datetime',
        'ebay_export_rows' => 'array',
    ];

    /**
     * Row-level visibility: admin = all; seller = own seller_id;
     * group_leader = group sellers UNION own; roleless = none.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        if ($user->hasRole('group_leader')) {
            if ($user->sales_group_id === null) {
                return $query->where('seller_id', $user->id);
            }

            return $query->where(function (Builder $q) use ($user) {
                $q->where('seller_id', $user->id)
                    ->orWhereHas('seller', function (Builder $s) use ($user) {
                        $s->where('sales_group_id', $user->sales_group_id);
                    });
            });
        }

        if ($user->hasRole('seller')) {
            return $query->where('seller_id', $user->id);
        }

        return $query->whereRaw('0 = 1');
    }

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
