<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('printify_orders', function (Blueprint $table) { $table->boolean('has_conflict')->default(false)->after('intent_state'); }); } public function down(): void { Schema::table('printify_orders', function (Blueprint $table) { $table->dropColumn('has_conflict'); }); } };
