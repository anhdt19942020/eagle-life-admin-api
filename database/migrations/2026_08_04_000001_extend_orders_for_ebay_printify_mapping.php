<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ebay_order_number')->nullable()->after('ebay_order_id');
        });

        DB::table('orders')->orderBy('id')->each(function (object $order): void {
            $number = trim((string) $order->ebay_order_id);
            if ($number === '') {
                throw new RuntimeException("Order {$order->id} has no eBay order number.");
            }

            DB::table('orders')->where('id', $order->id)->update(['ebay_order_number' => $number]);
        });

        if (DB::table('orders')->select('ebay_order_number')->groupBy('ebay_order_number')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot create the canonical eBay order key because duplicate values exist.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('ebay_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['ebay_order_number']);
            $table->dropColumn('ebay_order_number');
        });
    }
};
