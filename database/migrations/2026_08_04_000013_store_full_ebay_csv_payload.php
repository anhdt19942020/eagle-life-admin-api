<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('ebay_export_rows')->nullable()->after('ebay_order_number');
            $table->string('ebay_buyer_username')->nullable()->after('ebay_export_rows');
            $table->string('ebay_buyer_name')->nullable()->after('ebay_buyer_username');
            $table->string('ebay_buyer_email')->nullable()->after('ebay_buyer_name');
        });

        Schema::table('order_line_items', function (Blueprint $table) {
            $table->json('ebay_raw')->nullable()->after('currency');
            $table->decimal('shipping_amount', 12, 2)->nullable()->after('ebay_raw');
            $table->decimal('total_amount', 12, 2)->nullable()->after('shipping_amount');
            $table->string('shipping_service')->nullable()->after('total_amount');
            $table->string('tracking_number')->nullable()->after('shipping_service');
        });

        Schema::table('order_fulfillment_addresses', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->after('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ebay_export_rows', 'ebay_buyer_username', 'ebay_buyer_name', 'ebay_buyer_email']);
        });

        Schema::table('order_line_items', function (Blueprint $table) {
            $table->dropColumn(['ebay_raw', 'shipping_amount', 'total_amount', 'shipping_service', 'tracking_number']);
        });

        Schema::table('order_fulfillment_addresses', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
