<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printify_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->text('api_key');
            $table->boolean('is_active')->default(true);
            $table->foreignId('key_rotated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('key_rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printify_accounts');
    }
};
