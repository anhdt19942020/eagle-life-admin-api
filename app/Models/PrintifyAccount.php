<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintifyAccount extends Model
{
    protected $fillable = [
        'email',
        'api_key',
        'is_active',
        'key_rotated_by',
        'key_rotated_at',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'key_rotated_at' => 'datetime',
        ];
    }

    public function shops(): HasMany
    {
        return $this->hasMany(PrintifyShop::class, 'printify_account_id');
    }

    // Distinct-seller count across this account's shops is no longer a simple hasManyThrough
    // (seller<->shop is many-to-many now) — see PrintifyAccountController::attachAssignedUserCounts().

    public function keyRotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'key_rotated_by');
    }
}
