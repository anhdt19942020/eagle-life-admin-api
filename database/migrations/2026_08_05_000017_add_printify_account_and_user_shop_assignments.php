<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printify_shops', function (Blueprint $table) {
            $table->foreignId('printify_account_id')
                ->nullable()
                ->after('id')
                ->constrained('printify_accounts')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('printify_shop_id')
                ->nullable()
                ->unique()
                ->after('sales_group_id')
                ->constrained('printify_shops')
                ->nullOnDelete();
            $table->foreignId('printify_shop_assigned_by')
                ->nullable()
                ->after('printify_shop_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('printify_shop_assigned_at')->nullable()->after('printify_shop_assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('printify_shop_assigned_by');
            $table->dropColumn('printify_shop_assigned_at');
            // dropForeign before dropUnique: on MySQL/InnoDB, the FK on printify_shop_id reuses its
            // unique index as its supporting index, so dropping the index first while the FK is still
            // live fails with "Cannot drop index ... needed in a foreign key constraint" (error 1553).
            $table->dropForeign(['printify_shop_id']);
            $table->dropUnique(['printify_shop_id']);
            $table->dropColumn('printify_shop_id');
        });

        Schema::table('printify_shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('printify_account_id');
        });
    }
};
