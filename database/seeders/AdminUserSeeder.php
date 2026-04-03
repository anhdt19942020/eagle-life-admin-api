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
        $admin = User::firstOrCreate(
            ['email' => 'admin@eaglelife.com'],
            [
                'employee_code' => 'ADMIN001',
                'username' => 'admin',
                'name' => 'System Admin',
                'password' => Hash::make('12345678'),
                'phone' => '0987654321',
                'status' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole('admin');
    }
}
