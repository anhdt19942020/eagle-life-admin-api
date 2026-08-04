<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('printify_product_variants', function (Blueprint $table) {
            $table->id(); $table->foreignId('printify_product_id')->constrained()->cascadeOnDelete(); $table->string('printify_variant_id'); $table->string('sku')->nullable(); $table->string('title')->nullable(); $table->boolean('is_enabled')->default(true); $table->decimal('price', 12, 2)->nullable(); $table->timestamps();
            $table->unique(['printify_product_id', 'printify_variant_id'], 'ppv_product_variant_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('printify_product_variants'); }
};
