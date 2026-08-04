<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('printify_orders', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete(); $table->foreignId('printify_shop_id')->constrained()->cascadeOnDelete(); $table->string('printify_order_id'); $table->string('ebay_order_number')->nullable(); $table->string('status')->nullable(); $table->string('intent_state')->default('synced'); $table->string('attempt_key')->nullable()->unique(); $table->timestamp('synced_at')->nullable(); $table->timestamps();
            $table->unique(['printify_shop_id', 'printify_order_id']); $table->unique(['printify_shop_id', 'ebay_order_number']);
        });
    }
    public function down(): void { Schema::dropIfExists('printify_orders'); }
};
