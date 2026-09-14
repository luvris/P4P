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
        return response()->json([
            'message' => 'เข้าสู่ระบบสำเร็จ',
            'user' => $user,
        ], 200);
    }
}