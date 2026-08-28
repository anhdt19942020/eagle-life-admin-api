<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Multi-account FE gates sidebar on printify.accounts.view.
 * Prod admin still had legacy VN permission names after account deploy
 * because RolePermissionSeeder was not re-run — menu stayed hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolePermissionSeeder)->run();
    }

    public function down(): void
    {
        // Permissions remain; do not strip admin capabilities on rollback.
    }
};
