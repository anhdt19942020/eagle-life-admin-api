<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderImportBatchItem extends Model
{
    protected $fillable = ['order_import_batch_id', 'order_id', 'ebay_order_number', 'source_row', 'outcome', 'was_created', 'before_values'];
    protected $casts = ['was_created' => 'boolean', 'before_values' => 'encrypted:array'];
}
