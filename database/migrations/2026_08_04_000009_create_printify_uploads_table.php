<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('printify_uploads', function (Blueprint $table) {
            $table->id(); $table->string('printify_upload_id')->unique(); $table->string('file_name')->nullable(); $table->string('preview_url')->nullable(); $table->timestamp('synced_at')->nullable(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('printify_uploads'); }
};
