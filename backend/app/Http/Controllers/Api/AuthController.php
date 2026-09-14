<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // 1. Validate ข้อมูลที่ส่งมา
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // 2. ค้นหา User จาก username
        $user = User::where('username', $request->username)->first();

        // 3. ตรวจสอบ User และ Password
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'
            ], 401);
        }
        // 4. ส่งข้อมูล User กลับไป
        // สร้าง Token (Sanctum)
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'เข้าสู่ระบบสำเร็จ',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $token,
        ], 200);
    }

    public function logout(Request $request)
    {
        // ลบ token ปัจจุบัน
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'ออกจากระบบสำเร็จ'
        ], 200);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()
        ], 200);
    }
}
