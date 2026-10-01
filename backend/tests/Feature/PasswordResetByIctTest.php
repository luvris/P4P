<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * รีเซ็ตรหัสผ่านโดยเจ้าหน้าที่ไอที
 *
 * ระบบนี้ไม่ใช้การกู้รหัสผ่านทางอีเมล คำสั่งนี้จึงเป็นทางกลับเข้าระบบทางเดียว
 * เมื่อผู้ใช้ลืมรหัสผ่าน และต้องตัด session เดิมทั้งหมดด้วย
 */
class PasswordResetByIctTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        // ระบบจริงเก็บ session ไว้ในฐานข้อมูล (ค่าเริ่มต้นของเทสต์คือ array)
        config(['session.driver' => 'database']);

        $this->hr = User::create([
            'name'     => 'เจ้าหน้าที่ HR',
            'username' => 'hr',
            'email'    => 'hr@example.test',
            'password' => bcrypt('old-password-123'),
            'role'     => 'hr',
        ]);

        $this->finance = User::create([
            'name'     => 'เจ้าหน้าที่การเงิน',
            'username' => 'finance',
            'email'    => 'finance@example.test',
            'password' => bcrypt('old-password-456'),
            'role'     => 'finance',
        ]);
    }

    /** สร้าง session ที่ค้างอยู่ของผู้ใช้รายหนึ่ง */
    private function createSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id'            => $id,
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'test',
            'payload'       => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);
    }

    /** รันคำสั่งแล้วคืนรหัสผ่านชั่วคราวที่พิมพ์ออกหน้าจอ */
    private function runResetCommand(string $username): string
    {
        $buffer = new BufferedOutput();

        $exitCode = Artisan::call('user:password', ['username' => $username], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode, "คำสั่งต้องสำเร็จ\n{$output}");

        preg_match('/รหัสผ่านชั่วคราว:\s*(\S+)/u', $output, $matches);

        return $matches[1] ?? '';
    }

    public function test_it_resets_the_password_and_prints_it(): void
    {
        $password = $this->runResetCommand('hr');

        $this->assertNotSame('', $password, 'ต้องพิมพ์รหัสผ่านชั่วคราวออกทางหน้าจอ');
        $this->assertTrue(Hash::check($password, $this->hr->fresh()->password));
    }

    public function test_the_generated_password_is_not_the_old_one(): void
    {
        $password = $this->runResetCommand('hr');

        $this->assertNotSame('old-password-123', $password);
        $this->assertFalse(Hash::check('old-password-123', $this->hr->fresh()->password));
    }

    public function test_it_cuts_every_session_of_that_user(): void
    {
        $this->createSession($this->hr, 'session-hr-1');
        $this->createSession($this->hr, 'session-hr-2');
        $this->createSession($this->finance, 'session-finance-1');

        $this->runResetCommand('hr');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->hr->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->finance->id)->count());
    }

    public function test_it_does_not_touch_other_users(): void
    {
        $before = $this->finance->password;

        $this->runResetCommand('hr');

        $this->assertSame($before, $this->finance->fresh()->password);
    }

    public function test_it_fails_for_an_unknown_username(): void
    {
        $buffer = new BufferedOutput();

        $exitCode = Artisan::call('user:password', ['username' => 'nobody'], $buffer);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ไม่พบผู้ใช้ชื่อ', $buffer->fetch());

        // รหัสผ่านเดิมยังใช้ได้
        $this->assertTrue(Hash::check('old-password-123', $this->hr->fresh()->password));
    }

    public function test_revoking_sessions_keeps_the_current_one(): void
    {
        $this->createSession($this->hr, 'session-current');
        $this->createSession($this->hr, 'session-other');

        $deleted = $this->hr->revokeOtherSessions('session-current');

        $this->assertSame(1, $deleted);
        $this->assertSame(['session-current'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_revoking_sessions_does_nothing_when_sessions_are_not_in_the_database(): void
    {
        config(['session.driver' => 'array']);

        $this->createSession($this->hr, 'session-hr-1');

        $this->assertSame(0, $this->hr->revokeOtherSessions());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->hr->id)->count());
    }

    public function test_changing_the_password_through_the_api_cuts_the_other_devices(): void
    {
        $this->createSession($this->hr, 'session-other-device');

        $this->actingAs($this->hr)
            ->putJson('/api/profile/password', [
                'current_password'      => 'old-password-123',
                'password'              => 'new-password-789',
                'password_confirmation' => 'new-password-789',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password-789', $this->hr->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->hr->id)->count());
    }

    public function test_changing_the_password_also_removes_old_api_tokens(): void
    {
        $this->hr->createToken('auth_token');

        $this->assertSame(1, $this->hr->tokens()->count());

        $this->actingAs($this->hr)
            ->putJson('/api/profile/password', [
                'current_password'      => 'old-password-123',
                'password'              => 'new-password-789',
                'password_confirmation' => 'new-password-789',
            ])
            ->assertOk();

        $this->assertSame(0, $this->hr->tokens()->count());
    }
}
