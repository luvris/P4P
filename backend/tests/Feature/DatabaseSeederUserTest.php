<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ช่องโหว่เดิม: DatabaseSeeder ใช้ updateOrCreate() พร้อมกำหนด password คงที่
 * 'password123' ทำให้ (1) มีบัญชี admin ที่รหัสผ่านเป็นข้อมูลสาธารณะใน git และ
 * (2) การรัน db:seed ทับรหัสผ่านที่ผู้ใช้เปลี่ยนไปแล้วกลับเป็นค่าเดิม
 */
class DatabaseSeederUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_three_role_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertSame('admin', User::where('username', 'admin')->value('role'));
        $this->assertSame('hr', User::where('username', 'hr')->value('role'));
        $this->assertSame('finance', User::where('username', 'finance')->value('role'));
    }

    public function test_seeder_does_not_use_a_hardcoded_default_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (User::all() as $user) {
            $this->assertFalse(
                Hash::check('password123', $user->password),
                "บัญชี {$user->username} ยังถูกสร้างด้วยรหัสผ่านตายตัว password123"
            );
        }
    }

    public function test_seeder_does_not_reset_a_password_that_was_already_changed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('username', 'admin')->firstOrFail();
        $admin->update(['password' => 'my-own-strong-password']);

        // รัน seeder ซ้ำ — ต้องไม่แตะบัญชีที่มีอยู่แล้ว
        $this->seed(DatabaseSeeder::class);

        $admin->refresh();

        $this->assertSame(3, User::count(), 'seeder สร้างบัญชีซ้ำ');
        $this->assertTrue(
            Hash::check('my-own-strong-password', $admin->password),
            'seeder ทับรหัสผ่านที่ผู้ใช้เปลี่ยนไปแล้วกลับเป็นค่าเดิม'
        );
    }
}
