<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('imported_by')->nullable()->after('seller_id')->constrained('users')->nullOnDelete();
            $table->foreignId('synced_by')->nullable()->after('imported_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imported_by');
            $table->dropConstrainedForeignId('synced_by');
        });
    }
};
