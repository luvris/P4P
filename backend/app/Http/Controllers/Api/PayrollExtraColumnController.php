<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollExtraColumn;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Http\Request;

/**
 * คอลัมน์เพิ่มเติมของไฟล์เงินเดือน
 *
 * ผู้ใช้ประกาศชื่อคอลัมน์ที่ต้องการเพิ่ม ระบบจะใส่คอลัมน์นั้นลงไฟล์ต้นแบบ
 * (ยัดก่อน/หลังคอลัมน์ที่ระบุ ถ้าไม่ระบุจะต่อท้ายไฟล์)
 * และอ่านค่าจากไฟล์ที่อัปโหลดมาเก็บไว้ใน payrolls.extra_data
 *
 * จัดการโดย admin เท่านั้น เพราะคอลัมน์ที่เพิ่มจะกระทบไฟล์ต้นแบบของทุกคน
 */
class PayrollExtraColumnController extends Controller
{
    /**
     * รายการคอลัมน์ทั้งหมด พร้อมชื่อคอลัมน์มาตรฐานที่เลือกยึดตำแหน่งได้
     *
     * ส่ง base_columns ไปให้หน้าเว็บด้วย เพื่อให้ผู้ใช้เลือกได้ว่าจะยัดคอลัมน์ไว้ก่อน/หลัง
     * คอลัมน์ใดโดยไม่ต้องพิมพ์ชื่อเอง (พิมพ์ผิดแล้วคอลัมน์จะหายไปจากไฟล์ต้นแบบ)
     */
    public function index()
    {
        return response()->json([
            'data'         => PayrollExtraColumn::orderBy('sort_order')->orderBy('id')->get(),
            'base_columns' => NewFormatPayrollParser::TEMPLATE_COLUMNS,
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
        $baseColumns = NewFormatPayrollParser::TEMPLATE_COLUMNS;

        $data = $request->validate([
            'name'        => [
                'required', 'string', 'max:100',
                // ชื่อห้ามซ้ำ เพราะใช้จับคอลัมน์ตอนอ่านไฟล์
                \Illuminate\Validation\Rule::unique('payroll_extra_columns', 'name')
                    ->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            // ยึดตำแหน่งในไฟล์: ก่อน/หลังคอลัมน์ที่เลือก — ว่างทั้งคู่ = ต่อท้ายไฟล์
            // ต้องเป็นชื่อคอลัมน์ที่มีจริง ไม่งั้นคอลัมน์นี้จะหายไปจากไฟล์ต้นแบบเงียบ ๆ
            'before_column' => ['nullable', 'string', 'max:100', \Illuminate\Validation\Rule::in($baseColumns)],
            'after_column'  => ['nullable', 'string', 'max:100', \Illuminate\Validation\Rule::in($baseColumns)],
            'data_type'   => ['nullable', \Illuminate\Validation\Rule::in(PayrollExtraColumn::TYPES)],
            'is_active'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ], [
            'name.required'      => 'กรุณากรอกชื่อคอลัมน์',
            'name.unique'        => 'มีคอลัมน์ชื่อนี้อยู่แล้ว',
            'name.max'           => 'ชื่อคอลัมน์ยาวเกิน 100 ตัวอักษร',
            'data_type.in'       => 'ชนิดข้อมูลต้องเป็น text, number หรือ date',
            'before_column.in'   => 'ไม่พบคอลัมน์ที่เลือกในแบบฟอร์ม กรุณาเลือกใหม่',
            'after_column.in'    => 'ไม่พบคอลัมน์ที่เลือกในแบบฟอร์ม กรุณาเลือกใหม่',
        ]);

        // ค่าว่างไม่ต้องส่งต่อไป — ให้ฐานข้อมูลใช้ค่าเริ่มต้นของแต่ละคอลัมน์
        $data = array_filter($data, fn ($value) => $value !== null);

        // ตำแหน่งต้องเขียนทับเสมอ แม้ค่าจะเป็น null (หมายถึงย้ายไปต่อท้ายไฟล์)
        // ถ้าไม่ทำแบบนี้ คอลัมน์ที่เคยยึดตำแหน่งไว้จะยังคงตำแหน่งเดิมต่อไป
        if ($request->has('before_column') || $request->has('after_column')) {
            $before = $this->anchor($request->input('before_column'));

            // เลือกได้ทางเดียว — ถ้าตั้งทั้งสองทางให้ยึด "ก่อน"
            $data['before_column'] = $before;
            $data['after_column'] = $before === null ? $this->anchor($request->input('after_column')) : null;
        }

        return $data;
    }

    /**
     * ชื่อคอลัมน์ที่ใช้ยึดตำแหน่ง — คืน null เมื่อไม่ได้ระบุ
     */
    protected function anchor(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}