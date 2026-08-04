<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PrintifyProductVariant extends Model { protected $fillable = ['printify_product_id','printify_variant_id','sku','title','is_enabled','price']; protected $casts = ['is_enabled'=>'boolean','price'=>'decimal:2']; public function product(): BelongsTo { return $this->belongsTo(PrintifyProduct::class, 'printify_product_id'); } }
