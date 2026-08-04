<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('order_import_batch_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_import_batch_id')->constrained()->cascadeOnDelete(); $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ebay_order_number')->nullable(); $table->unsignedInteger('source_row')->nullable(); $table->string('outcome'); $table->boolean('was_created')->default(false); $table->text('before_values')->nullable(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('order_import_batch_items'); }
};
