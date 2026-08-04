<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('printify_products', function (Blueprint $table) {
            $table->id(); $table->foreignId('printify_shop_id')->constrained()->cascadeOnDelete(); $table->string('printify_product_id'); $table->string('title'); $table->string('status')->nullable(); $table->string('blueprint_id')->nullable(); $table->string('print_provider_id')->nullable(); $table->timestamp('synced_at')->nullable(); $table->timestamps();
            $table->unique(['printify_shop_id', 'printify_product_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('printify_products'); }
};
