<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin' || $this->user()?->role === 'hr';
    }

    /**
     * กฎการ validate
     */
    public function rules(): array
    {
        return [
            'citizen_id' => [
                'required',
                'string',
                'digits:13',
                Rule::unique('employees', 'citizen_id'),
            ],
            'prefix_id'        => ['required', 'exists:prefixes,id'],
            'first_name'       => ['required', 'string', 'max:255'],
            'last_name'        => ['required', 'string', 'max:255'],
            'position_number'  => ['nullable', 'string', 'max:50'],
            'salary'           => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'employee_type_id' => ['required', 'exists:employee_types,id'],
            'position_id'      => ['required', 'exists:positions,id'],
            'duty_id'          => ['required', 'exists:duties,id'],
            'group_id'         => ['required', 'exists:groups,id'],
            'work_id'          => ['required', 'exists:works,id'],
            'department_id'    => ['required', 'exists:departments,id'],
            'status_id'        => ['required', 'exists:employee_statuses,id'],
            'bank_account'     => ['nullable', 'string', 'max:30'],
            'note'             => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * ข้อความ error ภาษาไทย
     */
    public function messages(): array
    {
        return [
            'citizen_id.required' => 'กรุณากรอกเลขบัตรประชาชน',
            'citizen_id.digits'   => 'เลขบัตรประชาชนต้องมี 13 หลัก',
            'citizen_id.unique'   => 'เลขบัตรประชาชนนี้มีในระบบแล้ว',

            'prefix_id.required'  => 'กรุณาเลือกคำนำหน้า',
            'prefix_id.exists'    => 'คำนำหน้าที่เลือกไม่ถูกต้อง',

            'first_name.required' => 'กรุณากรอกชื่อ',
            'first_name.max'      => 'ชื่อยาวเกินไป (ไม่เกิน 255 ตัวอักษร)',

            'last_name.required'  => 'กรุณากรอกนามสกุล',
            'last_name.max'       => 'นามสกุลยาวเกินไป (ไม่เกิน 255 ตัวอักษร)',

            'salary.numeric'      => 'เงินเดือนต้องเป็นตัวเลข',
            'salary.min'          => 'เงินเดือนต้องไม่ติดลบ',

            'employee_type_id.required' => 'กรุณาเลือกประเภทบุคลากร',
            'employee_type_id.exists'   => 'ประเภทบุคลากรไม่ถูกต้อง',

            'position_id.required' => 'กรุณาเลือกตำแหน่ง',
            'position_id.exists'   => 'ตำแหน่งไม่ถูกต้อง',

            'duty_id.required'     => 'กรุณาเลือกการกิจ',
            'duty_id.exists'       => 'การกิจไม่ถูกต้อง',

            'group_id.required'    => 'กรุณาเลือกกลุ่มงาน',
            'group_id.exists'      => 'กลุ่มงานไม่ถูกต้อง',

            'work_id.required'     => 'กรุณาเลือกงาน',
            'work_id.exists'       => 'งานไม่ถูกต้อง',

            'department_id.required' => 'กรุณาเลือกหน่วย',
            'department_id.exists'   => 'หน่วยไม่ถูกต้อง',

            'status_id.required'   => 'กรุณาเลือกสถานะ',
            'status_id.exists'     => 'สถานะไม่ถูกต้อง',

            'bank_account.max'     => 'เลขที่บัญชียาวเกินไป',
            'note.max'             => 'หมายเหตุยาวเกินไป (ไม่เกิน 2000 ตัวอักษร)',
        ];
    }

    /**
     * ชื่อ field ภาษาไทย (สำหรับ error message)
     */
    public function attributes(): array
    {
        return [
            'citizen_id'       => 'เลขบัตรประชาชน',
            'prefix_id'        => 'คำนำหน้า',
            'first_name'       => 'ชื่อ',
            'last_name'        => 'นามสกุล',
            'position_number'  => 'เลขที่ตำแหน่ง',
            'salary'           => 'เงินเดือน',
            'employee_type_id' => 'ประเภทบุคลากร',
            'position_id'      => 'ตำแหน่ง',
            'duty_id'          => 'การกิจ',
            'group_id'         => 'กลุ่มงาน',
            'work_id'          => 'งาน',
            'department_id'    => 'หน่วย',
            'status_id'        => 'สถานะ',
            'bank_account'     => 'เลขที่บัญชี',
            'note'             => 'หมายเหตุ',
        ];
    }
}
