<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('platform'); // ebay | tiktok | amazon
            $table->string('code')->nullable()->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('platform');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sales_group_id')
                ->nullable()
                ->after('status')
                ->constrained('sales_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_group_id');
        });

        Schema::dropIfExists('sales_groups');
    }
};
