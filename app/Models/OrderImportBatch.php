<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderImportBatch extends Model
{
    protected $fillable = ['created_by', 'source_filename', 'status', 'row_count', 'order_count', 'created_count', 'updated_count', 'failed_count'];

    public function items(): HasMany { return $this->hasMany(OrderImportBatchItem::class); }
}
