<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PrintifyOrder extends Model { protected $fillable = ['order_id','printify_shop_id','printify_order_id','ebay_order_number','status','intent_state','has_conflict','attempt_key','synced_at']; protected $casts = ['has_conflict'=>'boolean','synced_at'=>'datetime']; public function shop(): BelongsTo { return $this->belongsTo(PrintifyShop::class, 'printify_shop_id'); } public function order(): BelongsTo { return $this->belongsTo(Order::class); } }
