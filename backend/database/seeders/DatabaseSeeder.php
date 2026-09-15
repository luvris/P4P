<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            EmployeeFromPayrollSeeder::class,
        ]);

        // Admin
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name'     => 'Admin System',
                'email'    => 'admin@cmneuro.go.th',
                'password' => Hash::make('password123'),
                'role'     => 'admin',
            ]
        );

        // HR
        User::updateOrCreate(
            ['username' => 'hr'],
            [
                'name'     => 'HR Manager',
                'email'    => 'hr@cmneuro.go.th',
                'password' => Hash::make('password123'),
                'role'     => 'hr',
            ]
        );

        // Finance
        User::updateOrCreate(
            ['username' => 'finance'],
            [
                'name'     => 'Finance Officer',
                'email'    => 'finance@cmneuro.go.th',
                'password' => Hash::make('password123'),
                'role'     => 'finance',
            ]
        );
    }
}
