<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintifyShop extends Model
{
    protected $fillable = [
        'printify_shop_id',
        'title',
        'is_active',
        'orders_sync_state',
        'orders_sync_completed_at',
        'orders_sync_watermark',
        'manual_approval_confirmed_by',
        'manual_approval_confirmed_at',
        'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'orders_sync_completed_at' => 'datetime',
        'manual_approval_confirmed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(PrintifyProduct::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PrintifyOrder::class);
    }

    public function isReadyForCreation(): bool
    {
        // Per-shop gate: other shops' sync/approval state must not block this shop.
        return $this->is_active
            && $this->manual_approval_confirmed_at !== null
            && $this->orders_sync_state === 'complete'
            && ! $this->orders()->where('has_conflict', true)->exists();
    }
}
