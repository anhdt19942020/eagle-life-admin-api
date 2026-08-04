<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('order_fulfillment_addresses', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name'); $table->string('last_name')->nullable(); $table->string('email')->nullable(); $table->string('phone')->nullable();
            $table->string('address_line1'); $table->string('address_line2')->nullable(); $table->string('city'); $table->string('region')->nullable(); $table->string('postal_code'); $table->char('country_code', 2);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('order_fulfillment_addresses'); }
};
