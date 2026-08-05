<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printify_shops', function (Blueprint $table) {
            $table->string('default_sku')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('printify_shops', function (Blueprint $table) {
            $table->dropColumn('default_sku');
        });
    }
};
