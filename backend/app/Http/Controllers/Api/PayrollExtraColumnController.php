<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollExtraColumn;
use Illuminate\Http\Request;

/**
 * คอลัมน์เพิ่มเติมของไฟล์เงินเดือน
 *
 * ผู้ใช้ประกาศชื่อคอลัมน์ที่ต้องการเพิ่ม ระบบจะใส่คอลัมน์นั้นลงไฟล์ต้นแบบ
 * (ถ้าระบุ after_column จะยัดไว้ต่อจากคอลัมน์นั้น ถ้าไม่ระบุจะต่อท้ายไฟล์)
 * และอ่านค่าจากไฟล์ที่อัปโหลดมาเก็บไว้ใน payrolls.extra_data
 *
 * จัดการโดย admin เท่านั้น เพราะคอลัมน์ที่เพิ่มจะกระทบไฟล์ต้นแบบของทุกคน
 */
class PayrollExtraColumnController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PayrollExtraColumn::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    /**
     * เพิ่มคอลัมน์ใหม่
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $data['key'] = PayrollExtraColumn::makeKey($data['name']);
        $data['created_by'] = $request->user()->id;

        if (! isset($data['sort_order'])) {
            $data['sort_order'] = (int) PayrollExtraColumn::max('sort_order') + 1;
        }

        $column = PayrollExtraColumn::create($data);

        return response()->json([
            'message' => 'เพิ่มคอลัมน์แล้ว — ดาวน์โหลดแบบฟอร์มใหม่เพื่อกรอกคอลัมน์นี้',
            'data'    => $column,
        ], 201);
    }

    /**
     * แก้ไขคอลัมน์
     *
     * key เปลี่ยนไม่ได้ เพราะข้อมูลที่เก็บไว้ใน extra_data ผูกกับ key เดิม
     * ถ้าจะเปลี่ยนชื่อจริงให้แก้ name เท่านั้น
     */
    public function update(Request $request, PayrollExtraColumn $column)
    {
        $data = $this->validated($request, $column);

        $column->update($data);

        return response()->json([
            'message' => 'บันทึกคอลัมน์แล้ว',
            'data'    => $column->fresh(),
        ]);
    }

    /**
     * ปิด/เปิดใช้งานคอลัมน์
     *
     * ไม่ลบจริง เพราะ payrolls.extra_data ที่เก็บไว้จะอ้าง key นี้
     * การปิดใช้งานจึงทำให้ไฟล์ต้นแบบไม่มีคอลัมน์นั้นแล้ว
     * แต่ข้อมูลเก่ายังอยู่ครบ
     */
    public function destroy(PayrollExtraColumn $column)
    {
        $column->update(['is_active' => false]);

        return response()->json([
            'message' => 'ปิดใช้งานคอลัมน์แล้ว — ข้อมูลที่บันทึกไว้ยังอยู่ครบ',
            'data'    => $column->fresh(),
        ]);
    }

    /**
     * ตรวจข้อมูลที่ส่งมา
     *
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?PayrollExtraColumn $column = null): array
    {
        $ignoreId = $column?->id;

        $data = $request->validate([
            'name'        => [
                'required', 'string', 'max:100',
                // ชื่อห้ามซ้ำ เพราะใช้จับคอลัมน์ตอนอ่านไฟล์
                \Illuminate\Validation\Rule::unique('payroll_extra_columns', 'name')
                    ->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            // ชื่อคอลัมน์ที่ต้องการให้คอลัมน์นี้ต่อจาก — ว่าง = ต่อท้ายไฟล์
            'after_column' => ['nullable', 'string', 'max:100'],
            'data_type'   => ['nullable', \Illuminate\Validation\Rule::in(PayrollExtraColumn::TYPES)],
            'is_active'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ], [
            'name.required' => 'กรุณากรอกชื่อคอลัมน์',
            'name.unique'   => 'มีคอลัมน์ชื่อนี้อยู่แล้ว',
            'name.max'      => 'ชื่อคอลัมน์ยาวเกิน 100 ตัวอักษร',
            'data_type.in'  => 'ชนิดข้อมูลต้องเป็น text, number หรือ date',
        ]);

        // ค่าว่างไม่ต้องส่งต่อไป — ให้ฐานข้อมูลใช้ค่าเริ่มต้นของแต่ละคอลัมน์
        return array_filter($data, fn ($value) => $value !== null);
    }
}