<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderFulfillmentAddress extends Model
{
    protected $fillable = [
        'order_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address_line1',
        'address_line2',
        'city',
        'region',
        'postal_code',
        'country_code',
        'country',
    ];

    public function toPrintifyAddress(): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address1' => $this->address_line1,
            'address2' => $this->address_line2,
            'city' => $this->city,
            'region' => $this->region,
            'zip' => $this->postal_code,
            'country' => $this->country_code ?: $this->country,
        ];
    }
}
