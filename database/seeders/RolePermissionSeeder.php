<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'api';

    private const PERMISSIONS = [
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'roles.view',
        'sales-groups.view',
        'sales-groups.create',
        'sales-groups.update',
        'sales-groups.delete',
        'orders.view',
        'orders.create',
        'orders.update',
        'orders.delete',
        'orders.import',
        'orders.export',
        'printify.catalog.view',
        'printify.sync',
        'printify.shop-readiness.confirm',
        'printify.order.create',
        'printify.reconcile',
        'printify.accounts.view',
        'printify.accounts.manage',
    ];

    private const OBSOLETE_PERMISSIONS = [
        'xem nhân viên', 'tạo nhân viên', 'sửa nhân viên', 'xóa nhân viên',
        'xem vai trò', 'tạo vai trò', 'sửa vai trò', 'xóa vai trò',
        'xem đơn hàng', 'tạo đơn hàng', 'sửa đơn hàng', 'xóa đơn hàng',
        'nhập đơn hàng', 'xuất đơn hàng',
    ];

    private const ROLE_MIGRATIONS = [
        'manager' => 'group_leader',
        'sale' => 'seller',
        'buyer' => 'seller',
    ];

    public function up(): void
    {
        $this->run();
    }

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => self::GUARD]);
        $seller = Role::firstOrCreate(['name' => 'seller', 'guard_name' => self::GUARD]);
        $groupLeader = Role::firstOrCreate(['name' => 'group_leader', 'guard_name' => self::GUARD]);

        $this->migrateLegacyRoleAssignments();

        $admin->syncPermissions(Permission::where('guard_name', self::GUARD)->whereIn('name', self::PERMISSIONS)->get());

        $groupLeader->syncPermissions([
            'orders.view', 'orders.create', 'orders.update', 'orders.delete',
            'orders.import', 'orders.export',
            'printify.catalog.view',
            'printify.shop-readiness.confirm', 'printify.order.create', 'printify.reconcile',
        ]);

        $seller->syncPermissions([
            'orders.view', 'orders.create', 'orders.update',
            'orders.import',
            'printify.order.create',
        ]);

        foreach (array_keys(self::ROLE_MIGRATIONS) as $oldName) {
            Role::where('name', $oldName)->where('guard_name', self::GUARD)->delete();
        }

        Permission::where('guard_name', self::GUARD)
            ->whereIn('name', self::OBSOLETE_PERMISSIONS)
            ->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function migrateLegacyRoleAssignments(): void
    {
        $roles = Role::where('guard_name', self::GUARD)
            ->whereIn('name', array_merge(array_keys(self::ROLE_MIGRATIONS), ['admin', 'seller', 'group_leader']))
            ->get()
            ->keyBy('name');

        foreach (self::ROLE_MIGRATIONS as $from => $to) {
            $fromRole = $roles->get($from);
            $toRole = $roles->get($to);
            if (! $fromRole || ! $toRole) {
                continue;
            }

            $assignments = DB::table('model_has_roles')
                ->where('role_id', $fromRole->id)
                ->get();

            foreach ($assignments as $row) {
                $exists = DB::table('model_has_roles')
                    ->where('role_id', $toRole->id)
                    ->where('model_type', $row->model_type)
                    ->where('model_id', $row->model_id)
                    ->exists();

                if (! $exists) {
                    DB::table('model_has_roles')->insert([
                        'role_id' => $toRole->id,
                        'model_type' => $row->model_type,
                        'model_id' => $row->model_id,
                    ]);
                }

                DB::table('model_has_roles')
                    ->where('role_id', $fromRole->id)
                    ->where('model_type', $row->model_type)
                    ->where('model_id', $row->model_id)
                    ->delete();
            }
        }
    }
}
