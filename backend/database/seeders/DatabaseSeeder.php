<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            EmployeeFromPayrollSeeder::class,
        ]);

        $this->createUser('admin', 'Admin System', 'admin@cmneuro.go.th', 'admin');
        $this->createUser('hr', 'HR Manager', 'hr@cmneuro.go.th', 'hr');
        $this->createUser('finance', 'Finance Officer', 'finance@cmneuro.go.th', 'finance');
    }

    /**
     * สร้างบัญชีผู้ใช้เฉพาะเมื่อยังไม่มีอยู่ในระบบ
     *
     * ใช้ firstOrCreate + ตรวจ existence ก่อน เพื่อไม่ให้การรัน seeder ทับรหัสผ่าน
     * ที่ผู้ใช้เปลี่ยนไปแล้วกลับเป็นค่าเดิม
     *
     * ก่อนหน้านี้ใช้ User::updateOrCreate() พร้อมกำหนด password ทุกครั้ง ทำให้
     * "php artisan db:seed" รีเซ็ตรหัสของทั้ง 3 บัญชีกลับเป็นค่าเดิมทุกครั้งที่รัน
     *
     * รหัสผ่านเริ่มต้นอ่านจาก SEED_DEFAULT_PASSWORD (ดู .env.example) หากไม่ได้ตั้งไว้
     * ระบบจะสุ่มรหัสให้และพิมพ์ออกทาง console ครั้งเดียว — ไม่มีรหัสผ่านตายตัวในโค้ด
     */
    private function createUser(string $username, string $name, string $email, string $role): void
    {
        if (User::where('username', $username)->exists()) {
            $this->command?->warn("ข้ามบัญชี {$username} — มีอยู่ในระบบแล้ว (ไม่แก้ไขรหัสผ่าน)");

            return;
        }

        $configuredPassword = env('SEED_DEFAULT_PASSWORD');

        if (is_string($configuredPassword) && $configuredPassword !== '') {
            $password = $configuredPassword;
        } else {
            $password = Str::password(24);
        }

        User::create([
            'username' => $username,
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
            'role'     => $role,
        ]);

        $this->command?->info("สร้างบัญชี {$username} ({$role}) เรียบร้อย");

        if ($configuredPassword === null) {
            $this->command?->line("  รหัสผ่านเริ่มต้น: {$password}");
            $this->command?->line('  กรุณาบันทึกไว้แล้วเปลี่ยนทันทีหลังเข้าสู่ระบบครั้งแรก');
        }
    }
}
