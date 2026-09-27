<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable so a queued/processing intent row (no remote id yet) can exist
        // before the Printify POST. MySQL unique (shop, printify_order_id) allows
        // multiple NULLs, so this is safe alongside the existing unique index.
        Schema::table('printify_orders', function (Blueprint $table) {
            $table->string('printify_order_id')->nullable()->change();
            $table->string('last_error', 500)->nullable()->after('intent_state');
        });
    }

    public function down(): void
    {
        if (DB::table('printify_orders')->whereNull('printify_order_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: printify_orders has rows with a null printify_order_id '.
                '(queued/processing/failed intent rows). Resolve or delete them before rollback.'
            );
        }

        Schema::table('printify_orders', function (Blueprint $table) {
            $table->dropColumn('last_error');
            $table->string('printify_order_id')->nullable(false)->change();
        });
    }
};
