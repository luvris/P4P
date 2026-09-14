<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin
        User::create([
            'name' => 'Admin System',
            'username' => 'admin',
            'email' => 'admin@cmneuro.go.th',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        // HR
        User::create([
            'name' => 'HR Manager',
            'username' => 'hr',
            'email' => 'hr@cmneuro.go.th',
            'password' => Hash::make('password123'),
            'role' => 'hr',
        ]);

        // Finance
        User::create([
            'name' => 'Finance Officer',
            'username' => 'finance',
            'email' => 'finance@cmneuro.go.th',
            'password' => Hash::make('password123'),
            'role' => 'finance',
        ]);
    }
}