<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintifyShop extends Model
{
    protected $fillable = [
        'printify_account_id',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(PrintifyAccount::class, 'printify_account_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(PrintifyShopUser::class)
            ->withPivot('is_default', 'assigned_by', 'assigned_at')
            ->withTimestamps();
    }

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
        return $this->readinessIssues() === [];
    }

    /**
     * Stable blocker codes consumed by the shop-management UI.
     *
     * @return list<string>
     */
    public function readinessIssues(): array
    {
        $this->loadMissing('account');

        $hasOrderConflicts = array_key_exists('has_order_conflicts', $this->attributes)
            ? (bool) $this->getAttribute('has_order_conflicts')
            : $this->orders()->where('has_conflict', true)->exists();

        return array_values(array_filter([
            ! $this->is_active ? 'shop_inactive' : null,
            $this->account === null || ! $this->account->is_active ? 'account_inactive' : null,
            ! $this->is_open ? 'shop_closed' : null,
            blank(trim((string) $this->default_sku)) ? 'missing_default_sku' : null,
            $this->manual_approval_confirmed_at === null ? 'manual_approval_required' : null,
            // orders_sync_state is informational only — sellers may create drafts before sync completes.
            $hasOrderConflicts ? 'order_conflicts' : null,
        ]));
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
