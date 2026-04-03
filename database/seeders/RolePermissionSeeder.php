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

        // 1. Tạo Quyền (tiếng Việt)
        $permissions = [
            // Nhân viên
            'xem nhân viên', 'tạo nhân viên', 'sửa nhân viên', 'xóa nhân viên',
            // Vai trò
            'xem vai trò', 'tạo vai trò', 'sửa vai trò', 'xóa vai trò',
            // Đơn hàng
            'xem đơn hàng', 'tạo đơn hàng', 'sửa đơn hàng', 'xóa đơn hàng',
            'nhập đơn hàng', 'xuất đơn hàng',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // 2. Tạo Vai trò
        $roleAdmin   = Role::firstOrCreate(['name' => 'admin',   'guard_name' => 'api']);
        $roleManager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'api']);
        $roleSale    = Role::firstOrCreate(['name' => 'sale',    'guard_name' => 'api']);
        $roleBuyer   = Role::firstOrCreate(['name' => 'buyer',   'guard_name' => 'api']); // đổi từ support

        // Admin: toàn quyền
        $roleAdmin->syncPermissions(Permission::all());

        // Manager: quản lý nhân viên + đơn hàng (không xóa nhân viên, không sửa vai trò)
        $roleManager->syncPermissions([
            'xem nhân viên', 'tạo nhân viên', 'sửa nhân viên',
            'xem đơn hàng', 'tạo đơn hàng', 'sửa đơn hàng', 'xóa đơn hàng',
            'nhập đơn hàng', 'xuất đơn hàng',
        ]);

        // Sale: chỉ xem và thao tác đơn hàng
        $roleSale->syncPermissions([
            'xem đơn hàng', 'tạo đơn hàng', 'sửa đơn hàng',
        ]);

        // Buyer: chỉ xem
        $roleBuyer->syncPermissions([
            'xem nhân viên', 'xem đơn hàng',
        ]);
    }
}
