<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * รีเซ็ตรหัสผ่านให้ผู้ใช้ โดยเจ้าหน้าที่ไอทีเป็นคนสั่ง
 *
 * ระบบนี้ไม่ใช้การกู้รหัสผ่านทางอีเมล คำสั่งนี้จึงเป็นทางเดียวที่ผู้ใช้จะกลับเข้าระบบได้
 * เมื่อลืมรหัสผ่าน — ทำงานได้เสมอ ไม่ต้องพึ่ง SMTP หรือบริการภายนอก
 *
 * รหัสผ่านที่สร้างจะแสดงเพียงครั้งเดียว และตัด session ของผู้ใช้รายนั้นทั้งหมด
 * เพื่อให้อุปกรณ์ที่ค้างอยู่ต้องล็อกอินใหม่ด้วยรหัสใหม่
 */
#[Signature('user:password {username : ชื่อผู้ใช้ที่ต้องการรีเซ็ตรหัสผ่าน}')]
#[Description('รีเซ็ตรหัสผ่านของผู้ใช้ (สำหรับเจ้าหน้าที่ไอที)')]
class ResetUserPassword extends Command
{
    /**
     * ตัวอักษรที่ใช้สร้างรหัสผ่าน
     *
     * ตัดตัวที่อ่านสับสนได้ออก (0 O 1 l I) เพราะรหัสนี้ต้องบอกกันทางโทรศัพท์
     */
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $username = (string) $this->argument('username');

        $user = User::where('username', $username)->first();

        if (! $user) {
            $this->error("ไม่พบผู้ใช้ชื่อ \"{$username}\"");

            $available = User::orderBy('username')->pluck('username')->implode(', ');
            if ($available !== '') {
                $this->line("ผู้ใช้ที่มีในระบบ: {$available}");
            }

            return self::FAILURE;
        }

        $password = $this->generatePassword();

        // cast 'hashed' ของโมเดล User จะ hash ให้อัตโนมัติ
        $user->update(['password' => $password]);

        // ตัดทุก session และ token ของผู้ใช้รายนี้ — อุปกรณ์ที่ค้างอยู่ต้องล็อกอินใหม่
        $sessions = $user->revokeOtherSessions();
        $tokens = $user->revokeOtherTokens();

        $this->newLine();
        $this->info("รีเซ็ตรหัสผ่านให้ {$user->username} ({$user->name}) เรียบร้อย");
        $this->newLine();
        $this->line('  รหัสผ่านชั่วคราว: ' . $password);
        $this->newLine();
        $this->warn('รหัสนี้จะแสดงเพียงครั้งเดียว กรุณาคัดลอกเก็บไว้ตอนนี้');
        $this->line('กรุณาแจ้งผู้ใช้ให้เปลี่ยนรหัสผ่านทันทีหลังเข้าสู่ระบบ');
        $this->line("ตัดการเชื่อมต่อเดิม: {$sessions} session, {$tokens} token");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * สร้างรหัสผ่านชั่วคราวแบบสุ่ม
     */
    private function generatePassword(): string
    {
        $max = strlen(self::ALPHABET) - 1;

        $password = '';
        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
