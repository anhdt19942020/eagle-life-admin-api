<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintifyShop extends Model
{
    protected $fillable = [
        'printify_shop_id',
        'title',
        'default_sku',
        'is_active',
        'is_open',
        'open_state_changed_by',
        'open_state_changed_at',
        'orders_sync_state',
        'orders_sync_completed_at',
        'orders_sync_watermark',
        'manual_approval_confirmed_by',
        'manual_approval_confirmed_at',
        'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_open' => 'boolean',
        'open_state_changed_at' => 'datetime',
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

    public function openStateChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'open_state_changed_by');
    }

    public function isReadyForCreation(): bool
    {
        // Per-shop gate: other shops' sync/approval state must not block this shop.
        return $this->is_active
            && $this->is_open
            && filled(trim((string) $this->default_sku))
            && $this->manual_approval_confirmed_at !== null
            && $this->orders_sync_state === 'complete'
            && ! $this->orders()->where('has_conflict', true)->exists();
    }

    public function setOpenState(bool $isOpen, ?int $userId): void
    {
        $this->forceFill([
            'is_open' => $isOpen,
            'open_state_changed_by' => $userId,
            'open_state_changed_at' => now(),
        ])->save();
    }
}
