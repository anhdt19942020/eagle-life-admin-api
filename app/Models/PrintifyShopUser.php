<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PrintifyShopUser extends Pivot
{
    protected $table = 'printify_shop_user';

    protected $casts = [
        'is_default' => 'boolean',
        'assigned_at' => 'datetime',
    ];
}
