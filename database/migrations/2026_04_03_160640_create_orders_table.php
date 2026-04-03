<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('ebay_order_id')->unique();                       // Mã đơn hàng eBay
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();   // Người đặt
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();  // Seller phụ trách
            $table->timestamp('ebay_created_at');                            // Thời gian tạo trên eBay
            $table->timestamp('printify_created_at')->nullable();            // Thời gian tạo trên Printify
            $table->string('printify_order_id')->nullable();                 // Mã Printify (cập nhật sau)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
