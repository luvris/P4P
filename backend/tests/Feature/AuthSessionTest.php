<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ตรวจการยืนยันตัวตนแบบ session cookie ของ Sanctum (โหมด SPA)
 * ซึ่งแทนที่ bearer token ที่ต้องเก็บใน localStorage
 */
class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    private function makeUser(string $username = 'session_user', string $role = 'hr'): User
    {
        return User::create([
            'name'     => 'Session Tester',
            'username' => $username,
            'email'    => "{$username}@example.test",
            'password' => bcrypt('secret-password'),
            'role'     => $role,
        ]);
    }

    public function test_login_authenticates_via_session_and_does_not_return_a_token(): void
    {
        $user = $this->makeUser();

        $response = $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'username' => 'session_user',
                'password' => 'secret-password',
            ]);

        $response->assertOk();

        // ไม่ควรมี token กลับไปให้ frontend เก็บอีกแล้ว
        $response->assertJsonMissingPath('token');
        $response->assertJsonPath('user.username', 'session_user');
        $response->assertJsonPath('user.role', 'hr');

        $this->assertAuthenticatedAs($user);
    }

    public function test_session_cookie_is_http_only_so_javascript_cannot_read_it(): void
    {
        $this->makeUser();

        $response = $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'username' => 'session_user',
                'password' => 'secret-password',
            ])->assertOk();

        $sessionCookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($sessionCookie, 'ไม่พบ session cookie ในคำตอบ');
        $this->assertTrue(
            $sessionCookie->isHttpOnly(),
            'session cookie ต้องเป็น HttpOnly เพื่อไม่ให้ JavaScript อ่านได้'
        );
    }

    public function test_login_with_a_wrong_password_is_rejected(): void
    {
        $this->makeUser();

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'username' => 'session_user',
                'password' => 'wrong-password',
            ])
            ->assertStatus(401);

        $this->assertGuest();
    }

    /**
     * เดิม: คำขอที่ไม่ได้ส่ง Accept: application/json จะถูกมองเป็นคำขอเว็บ
     * แล้ว Laravel พยายาม redirect ไป route ชื่อ "login" ซึ่งไม่มีในระบบนี้
     * ทำให้ได้ 500 (RouteNotFoundException) แทนที่จะเป็น 401
     */
    public function test_unauthenticated_api_request_returns_401_not_500(): void
    {
        $this->get('/api/profile', ['Accept' => '*/*'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_changing_password_revokes_tokens_of_other_devices(): void
    {
        $user = $this->makeUser();

        $currentToken = $user->createToken('this-device');
        $otherToken = $user->createToken('other-device');

        $this->withHeader('Authorization', 'Bearer ' . $currentToken->plainTextToken)
            ->putJson('/api/profile/password', [
                'current_password'      => 'secret-password',
                'password'              => 'brand-new-password-1',
                'password_confirmation' => 'brand-new-password-1',
            ])
            ->assertOk();

        // token ของเครื่องอื่นต้องถูกเพิกถอน ส่วนเครื่องที่เรียกคำขอนี้ยังใช้ได้
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);

        $this->assertTrue(Hash::check('brand-new-password-1', $user->fresh()->password));
    }

    public function test_changing_password_requires_the_current_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password'      => 'not-the-real-password',
                'password'              => 'brand-new-password-1',
                'password_confirmation' => 'brand-new-password-1',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('secret-password', $user->fresh()->password));
    }
}
