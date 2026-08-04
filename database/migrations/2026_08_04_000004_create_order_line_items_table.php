<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('order_line_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_id')->nullable(); $table->string('fallback_identity')->nullable(); $table->string('item_number')->nullable(); $table->string('title')->nullable(); $table->string('custom_label')->nullable(); $table->text('variation')->nullable();
            $table->unsignedInteger('quantity'); $table->decimal('unit_price', 12, 2)->nullable(); $table->string('currency', 3)->default('USD'); $table->timestamps();
            $table->unique(['order_id', 'transaction_id']); $table->unique(['order_id', 'fallback_identity']);
        });
    }
    public function down(): void { Schema::dropIfExists('order_line_items'); }
};
