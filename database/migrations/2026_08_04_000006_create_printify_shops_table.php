<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('printify_shops', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('printify_shop_id')->unique(); $table->string('title'); $table->boolean('is_active')->default(true);
            $table->string('orders_sync_state')->default('pending'); $table->timestamp('orders_sync_completed_at')->nullable(); $table->string('orders_sync_watermark')->nullable();
            $table->foreignId('manual_approval_confirmed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('manual_approval_confirmed_at')->nullable(); $table->timestamp('synced_at')->nullable(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('printify_shops'); }
};
