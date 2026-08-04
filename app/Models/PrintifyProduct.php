<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PrintifyProduct extends Model { protected $fillable = ['printify_shop_id','printify_product_id','title','status','blueprint_id','print_provider_id','synced_at']; protected $casts = ['synced_at'=>'datetime']; public function shop(): BelongsTo { return $this->belongsTo(PrintifyShop::class, 'printify_shop_id'); } public function variants(): HasMany { return $this->hasMany(PrintifyProductVariant::class); } }
