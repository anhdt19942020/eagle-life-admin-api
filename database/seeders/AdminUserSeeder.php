<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('AdminUserSeeder is disabled in production.');
        }

        $password = env('ADMIN_SEED_PASSWORD');
        if (!$password) {
            throw new \RuntimeException('Set ADMIN_SEED_PASSWORD explicitly before running AdminUserSeeder.');
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@eaglelife.com'],
            [
                'employee_code' => 'ADMIN001',
                'username' => 'admin',
                'name' => 'System Admin',
                'password' => Hash::make($password),
                'phone' => '0987654321',
                'status' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole('admin');

        $tronganh = User::firstOrCreate(
            ['email' => 'anhdt19942020@gmail.com'],
            [
                'employee_code'     => 'ADMIN002',
                'username'          => 'tronganh',
                'name'              => 'Trọng Anh',
                'password'          => Hash::make($password),
                'status'            => true,
                'email_verified_at' => now(),
            ]
        );

        $tronganh->assignRole('admin');
    }
}
