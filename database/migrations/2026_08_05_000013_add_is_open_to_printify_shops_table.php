<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printify_shops', function (Blueprint $table) {
            $table->boolean('is_open')->default(true)->after('is_active');
            $table->foreignId('open_state_changed_by')
                ->nullable()
                ->after('is_open')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('open_state_changed_at')->nullable()->after('open_state_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('printify_shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('open_state_changed_by');
            $table->dropColumn(['is_open', 'open_state_changed_at']);
        });
    }
};
