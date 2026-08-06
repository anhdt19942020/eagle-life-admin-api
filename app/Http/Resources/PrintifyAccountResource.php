<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintifyAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'is_active' => $this->is_active,
            // Presence check only — reads the still-encrypted raw column, never decrypts.
            'has_api_key' => filled($this->getRawOriginal('api_key')),
            'shop_count' => $this->whenCounted('shops'),
            'assigned_user_count' => $this->whenCounted('assignedUsers'),
            'shops' => PrintifyShopResource::collection($this->whenLoaded('shops')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
