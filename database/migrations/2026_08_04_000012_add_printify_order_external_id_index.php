<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('printify_orders', function (Blueprint $table) { $table->index('ebay_order_number'); }); } public function down(): void { Schema::table('printify_orders', function (Blueprint $table) { $table->dropIndex(['ebay_order_number']); }); } };
