<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ช่องโหว่เดิม: POST /api/login ไม่มีการจำกัดจำนวนครั้งเลย
 * ทำให้เดารหัสผ่านได้ไม่จำกัดจำนวนครั้ง (และกิน CPU จาก bcrypt ซ้ำ ๆ ได้ด้วย)
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();

        // cache store ในเทสเป็น array ซึ่งเป็น singleton — ล้างตัวนับก่อนทุกเทส
        RateLimiter::clear('login:user:session_user|127.0.0.1');
        RateLimiter::clear('login:ip:127.0.0.1');
    }

    private function makeUser(string $username): User
    {
        return User::create([
            'name'     => 'Throttle Tester',
            'username' => $username,
            'email'    => "{$username}@example.test",
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);
    }

    private function attempt(string $username, string $password)
    {
        return $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'username' => $username,
                'password' => $password,
            ]);
    }

    private function maxAttempts(): int
    {
        return (int) config('security.login_throttle.max_attempts');
    }

    public function test_failed_logins_are_throttled_after_the_limit(): void
    {
        $this->makeUser('session_user');

        for ($i = 0; $i < $this->maxAttempts(); $i++) {
            $this->attempt('session_user', 'wrong-password')->assertStatus(401);
        }

        // ครั้งถัดไปต้องถูกปฏิเสธด้วย 429 พร้อมบอกเวลาที่ต้องรอ
        $response = $this->attempt('session_user', 'wrong-password');

        $response->assertStatus(429);
        $this->assertStringContainsString('หลายครั้งเกินไป', $response->json('message'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_being_throttled_does_not_prevent_the_correct_password_after_decay(): void
    {
        $user = $this->makeUser('session_user');

        for ($i = 0; $i < $this->maxAttempts(); $i++) {
            $this->attempt('session_user', 'wrong-password')->assertStatus(401);
        }

        $this->attempt('session_user', 'wrong-password')->assertStatus(429);

        // ครบช่วงเวลาแล้วต้องเข้าได้ตามปกติ (จำลองการหมดอายุของตัวนับ)
        RateLimiter::clear('login:user:session_user|127.0.0.1');
        RateLimiter::clear('login:ip:127.0.0.1');

        $this->attempt('session_user', 'secret-password')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_is_never_blocked_by_the_limiter(): void
    {
        $this->makeUser('session_user');

        // การนับเฉพาะครั้งที่ล้มเหลว ทำให้เข้า-ออกหลายรอบติดกันก็ยังทำได้
        for ($i = 0; $i < $this->maxAttempts() * 2; $i++) {
            $this->attempt('session_user', 'secret-password')->assertOk();
        }
    }

    public function test_failed_logins_reset_after_a_successful_login(): void
    {
        $this->makeUser('session_user');

        // ผิดจนเกือบสุดขีด
        for ($i = 0; $i < $this->maxAttempts() - 1; $i++) {
            $this->attempt('session_user', 'wrong-password')->assertStatus(401);
        }

        // เข้าถูกต้องหนึ่งครั้ง → ล้างตัวนับของบัญชีนี้
        $this->attempt('session_user', 'secret-password')->assertOk();

        // ผิดได้อีกเต็มจำนวนโดยไม่ถูกจำกัด
        for ($i = 0; $i < $this->maxAttempts() - 1; $i++) {
            $this->attempt('session_user', 'wrong-password')->assertStatus(401);
        }
    }

    public function test_attacking_one_account_does_not_lock_out_a_colleague(): void
    {
        $this->makeUser('victim');
        $this->makeUser('colleague');

        RateLimiter::clear('login:user:victim|127.0.0.1');

        // ยิงรหัสผิดใส่บัญชี victim จนสุดขีด
        for ($i = 0; $i < $this->maxAttempts(); $i++) {
            $this->attempt('victim', 'wrong-password')->assertStatus(401);
        }

        $this->attempt('victim', 'wrong-password')->assertStatus(429);

        // เพื่อนร่วมงานที่ใช้ IP เดียวกัน (เช่นออกอินเทอร์เน็ตผ่าน NAT ของโรงพยาบาล)
        // ต้องยังเข้าสู่ระบบได้ตามปกติ
        $this->attempt('colleague', 'secret-password')->assertOk();
    }
}
