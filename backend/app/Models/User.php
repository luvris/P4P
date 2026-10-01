<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * เพิกถอน token ทั้งหมดของ User ยกเว้นตัวที่ระบุ
     *
     * ใช้เมื่อเปลี่ยนรหัสผ่าน เพื่อตัดการใช้งานของอุปกรณ์อื่นที่อาจถือ token เก่าอยู่
     * โดยคง token ที่ใช้เรียกคำขอนี้ไว้ไม่ให้ผู้ใช้ถูกไล่ออกจากเครื่องปัจจุบัน
     *
     * รองรับกรณี $keep เป็น null หรือ TransientToken (auth ผ่าน session/cookie)
     * ซึ่งทั้งสองกรณีจะลบ token ที่ค้างอยู่ทั้งหมด
     */
    public function revokeOtherTokens(mixed $keep = null): int
    {
        $keepId = is_object($keep) && method_exists($keep, 'getKey')
            ? $keep->getKey()
            : null;

        $query = $this->tokens();

        if ($keepId) {
            $query->where('id', '!=', $keepId);
        }

        return $query->delete();
    }

    /**
     * ตัด session ของอุปกรณ์อื่นของผู้ใช้รายนี้
     *
     * ระบบนี้ล็อกอินด้วย session cookie (SESSION_DRIVER=database)
     * จึงต้องลบแถวในตาราง sessions — ไม่ใช่ personal_access_tokens ที่เลิกใช้แล้ว
     *
     * @param  string|null  $keepSessionId  session ที่ต้องการคงไว้ (เครื่องที่กำลังใช้งานอยู่)
     * @return int จำนวน session ที่ถูกตัด
     */
    public function revokeOtherSessions(?string $keepSessionId = null): int
    {
        // driver อื่น (array / file) ไม่ได้เก็บ session ไว้ในฐานข้อมูล จึงไม่มีอะไรให้ลบ
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $query = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey());

        if ($keepSessionId) {
            $query->where('id', '!=', $keepSessionId);
        }

        return $query->delete();
    }

    // Helper methods
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isHr(): bool
    {
        return $this->role === 'hr';
    }

    public function isFinance(): bool
    {
        return $this->role === 'finance';
    }
}