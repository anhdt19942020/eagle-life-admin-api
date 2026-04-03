<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_code',
        'customer_name',
        'customer_phone',
        'customer_email',
        'total_amount',
        'status',
        'sale_id',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(User::class, 'sale_id');
    }

    public static function generateCode(): string
    {
        $latest = self::orderBy('id', 'desc')->first();
        $nextId = $latest ? $latest->id + 1 : 1;
        return 'DH' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }
}
