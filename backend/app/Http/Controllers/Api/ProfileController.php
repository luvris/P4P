<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * ข้อมูลผู้ใช้ที่ล็อกอินอยู่ (ไม่ส่ง password ออกไป)
     */
    public function show(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ], 200);
    }

    /**
     * แก้ไขข้อมูลส่วนตัว (ชื่อ / อีเมล)
     * username และ role เป็นข้อมูลของระบบ — แก้ไขผ่านหน้านี้ไม่ได้
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ], [
            'name.required'  => 'กรุณากรอกชื่อ-นามสกุล',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email'    => 'รูปแบบอีเมลไม่ถูกต้อง',
            'email.unique'   => 'อีเมลนี้ถูกใช้งานแล้ว',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'บันทึกข้อมูลส่วนตัวสำเร็จ',
            'user'    => $this->formatUser($user->fresh()),
        ], 200);
    }

    /**
     * เปลี่ยนรหัสผ่าน — ต้องยืนยันรหัสผ่านเดิมก่อน
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ], [
            'current_password.required' => 'กรุณากรอกรหัสผ่านเดิม',
            'password.required'         => 'กรุณากรอกรหัสผ่านใหม่',
            'password.min'              => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร',
            'password.confirmed'        => 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'รหัสผ่านเดิมไม่ถูกต้อง',
                'errors'  => ['current_password' => ['รหัสผ่านเดิมไม่ถูกต้อง']],
            ], 422);
        }

        // cast 'hashed' ของโมเดล User จะ hash รหัสผ่านให้อัตโนมัติ
        $user->update(['password' => $validated['password']]);

        // ตัดอุปกรณ์อื่นทั้งหมดที่ล็อกอินอยู่ โดยคงเครื่องที่กำลังใช้งานไว้
        // - session: ระบบนี้ล็อกอินด้วย session cookie จึงต้องลบแถวในตาราง sessions
        //   (ถ้า request นี้ไม่มี session เช่นเรียกด้วย bearer token ให้ตัดทั้งหมด)
        // - token: เผื่อ client อื่นที่ยังใช้ bearer token
        $user->revokeOtherSessions($request->hasSession() ? $request->session()->getId() : null);
        $user->revokeOtherTokens($request->user()->currentAccessToken());

        return response()->json([
            'message' => 'เปลี่ยนรหัสผ่านสำเร็จ',
        ], 200);
    }

    /**
     * รูปแบบข้อมูล user ที่ส่งให้ frontend (ไม่รวม password)
     */
    private function formatUser($user): array
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
