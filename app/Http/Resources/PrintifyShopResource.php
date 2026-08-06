<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintifyShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'printify_account_id' => $this->printify_account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->id,
                'email' => $this->account->email,
                'is_active' => $this->account->is_active,
            ]),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser === null ? null : [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
            ]),
            'printify_shop_id' => $this->printify_shop_id,
            'title' => $this->title,
            'default_sku' => $this->default_sku,
            'is_active' => $this->is_active,
            'is_open' => $this->is_open,
            'open_state_changed_at' => $this->open_state_changed_at,
            'orders_sync_state' => $this->orders_sync_state,
            'orders_sync_completed_at' => $this->orders_sync_completed_at,
            'manual_approval_confirmed_at' => $this->manual_approval_confirmed_at,
            'synced_at' => $this->synced_at,
            'ready_for_creation' => $this->isReadyForCreation(),
        ];
    }
}
