<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        \App\Models\User::updateOrCreate(
            ['email' => 'admin@matchday-africa.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        // Create regular user
        \App\Models\User::updateOrCreate(
            ['email' => 'user@matchday-africa.com'],
            [
                'name' => 'Regular User',
                'password' => bcrypt('password'),
                'role' => 'user',
                'is_admin' => false,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Test users created:');
        $this->command->info('Admin: admin@matchday-africa.com / password');
        $this->command->info('User: user@matchday-africa.com / password');
    }
}
