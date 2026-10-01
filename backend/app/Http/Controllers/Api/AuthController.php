<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // 1. Validate ข้อมูลที่ส่งมา
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // 2. ตรวจจำนวนครั้งที่ล้มเหลวก่อน — ต้องทำก่อนตรวจรหัสผ่าน
        //    ไม่งั้นคำขอที่ถูกจำกัดแล้วจะยังกิน CPU ไปกับการ hash (bcrypt) ทุกครั้ง
        if ($retryAfter = $this->tooManyFailedAttempts($request)) {
            return response()->json([
                'message' => "พยายามเข้าสู่ระบบหลายครั้งเกินไป กรุณารออีก {$retryAfter} วินาทีแล้วลองใหม่",
            ], 429, ['Retry-After' => $retryAfter]);
        }

        // 3. ค้นหา User จาก username
        $user = User::where('username', $request->username)->first();

        // 4. ตรวจสอบ User และ Password
        if (!$user || !Hash::check($request->password, $user->password)) {
            $this->recordFailedAttempt($request);

            return response()->json([
                'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'
            ], 401);
        }

        // 5. ต้องเป็นคำขอจาก frontend ที่ระบุใน config('sanctum.stateful') เท่านั้น
        //    ถ้าไม่ใช่จะไม่มี session ให้ผูก จึงไม่ควรตอบว่าสำเร็จ
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'คำขอไม่ได้มาจากโดเมนของ frontend ที่ระบบอนุญาต',
            ], 403);
        }

        // 6. เข้าสู่ระบบสำเร็จ — ล้างตัวนับของบัญชีนี้ เพื่อไม่ให้ผู้ใช้จริง
        //    ถูกล็อกจากการเข้า-ออกหลายรอบ (ตัวนับต่อ IP ไม่ล้าง เพื่อไม่ให้
        //    ผู้โจมตีล้างโควตาตัวเองด้วยการล็อกอินบัญชีที่ตัวเองมีอยู่)
        RateLimiter::clear($this->userThrottleKey($request));

        // 7. ยืนยันตัวตนผ่าน session (โหมด SPA ของ Sanctum)
        //    ระบบไม่ส่ง token กลับไปให้ frontend อีกแล้ว — ตัวตนอยู่ใน cookie
        //    ที่เป็น HttpOnly ซึ่ง JavaScript อ่านไม่ได้ จึงขโมยผ่าน XSS ไม่ได้
        Auth::guard('web')->login($user);

        // ป้องกัน session fixation — ออก session id ใหม่หลังยืนยันตัวตนสำเร็จ
        $request->session()->regenerate();

        return response()->json([
            'message' => 'เข้าสู่ระบบสำเร็จ',
            'user'    => $this->formatUser($user),
        ], 200);
    }

    public function logout(Request $request)
    {
        // ออกจากระบบเฉพาะ session ปัจจุบัน (อุปกรณ์นี้)
        // ส่วน "ออกจากระบบทุกอุปกรณ์" ใช้การเปลี่ยนรหัสผ่าน ซึ่งจะเพิกถอน token เครื่องอื่นให้
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'ออกจากระบบสำเร็จ'
        ], 200);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user())
        ], 200);
    }

    /**
     * ถ้าถูกล็อกอยู่ ให้คืนจำนวนวินาทีที่ต้องรอ มิฉะนั้นคืน null
     */
    private function tooManyFailedAttempts(Request $request): ?int
    {
        $limits = [
            $this->userThrottleKey($request) => $this->throttleConfig('max_attempts', 5),
            $this->ipThrottleKey($request)   => $this->throttleConfig('max_attempts_per_ip', 30),
        ];

        $longestWait = null;

        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $longestWait = max($longestWait ?? 0, RateLimiter::availableIn($key));
            }
        }

        return $longestWait;
    }

    /**
     * บันทึกความพยายามที่ล้มเหลวลงตัวนับทั้งสองชั้น
     */
    private function recordFailedAttempt(Request $request): void
    {
        $decay = $this->throttleConfig('decay_seconds', 60);

        RateLimiter::hit($this->userThrottleKey($request), $decay);
        RateLimiter::hit($this->ipThrottleKey($request), $decay);
    }

    /**
     * คีย์ต่อบัญชี — ใช้ทั้ง username และ IP เพื่อกันการยิงรหัสผิดถล่ม
     * จนล็อกบัญชีของผู้อื่น (account lockout DoS)
     */
    private function userThrottleKey(Request $request): string
    {
        $username = Str::lower(trim((string) $request->input('username', '')));

        return 'login:user:'.$username.'|'.$request->ip();
    }

    /**
     * คีย์ต่อ IP — กันการยิงไล่หลาย username จากเครื่องเดียว
     */
    private function ipThrottleKey(Request $request): string
    {
        return 'login:ip:'.$request->ip();
    }

    /**
     * อ่านค่าตั้งต้นของการจำกัดจำนวนครั้ง (config/security.php)
     */
    private function throttleConfig(string $key, int $default): int
    {
        return (int) config('security.login_throttle.'.$key, $default);
    }

    /**
     * รูปแบบข้อมูล user ที่ส่งให้ frontend (ไม่รวม password)
     */
    private function formatUser(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'username' => $user->username,
            'email'    => $user->email,
            'role'     => $user->role,
        ];
    }
}
