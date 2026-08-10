<?php

namespace App\Policies;

use App\Models\PrintifyShop;
use App\Models\User;

class PrintifyShopPolicy
{
    /**
     * Admin may act on any shop; seller/leader only on their assigned shop.
     */
    public function manage(User $user, PrintifyShop $shop): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->printifyShops->contains('id', $shop->id);
    }
}
