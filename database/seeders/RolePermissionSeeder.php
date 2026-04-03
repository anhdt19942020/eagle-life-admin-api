<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function up(): void
    {
        $this->run();
    }

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create Permissions
        $permissions = [
            // Users
            'users.view', 'users.create', 'users.edit', 'users.delete',
            // Roles
            'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
            // Orders
            'orders.view', 'orders.create', 'orders.edit', 'orders.delete',
            'orders.import', 'orders.export',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // 2. Create Roles and assign existing permissions
        $roleAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $roleManager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'api']);
        $roleSale = Role::firstOrCreate(['name' => 'sale', 'guard_name' => 'api']);
        $roleSupport = Role::firstOrCreate(['name' => 'support', 'guard_name' => 'api']);

        // Admin gets all permissions
        $roleAdmin->syncPermissions(Permission::all());

        // Manager gets most permissions except role management
        $roleManager->syncPermissions([
            'users.view', 'users.create', 'users.edit',
            'orders.view', 'orders.create', 'orders.edit', 'orders.delete', 'orders.import', 'orders.export'
        ]);

        // Sale only sees and manages their own orders (logic handled in policy/service), but basic permission:
        $roleSale->syncPermissions([
            'orders.view', 'orders.create', 'orders.edit'
        ]);

        // Support only views
        $roleSupport->syncPermissions([
            'users.view', 'orders.view'
        ]);
    }
}
