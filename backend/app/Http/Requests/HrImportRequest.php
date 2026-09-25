<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HrImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.file'     => 'ข้อมูลที่ส่งมาไม่ใช่ไฟล์',
            'file.mimes'    => 'รองรับเฉพาะไฟล์ .xlsx หรือ .xls',
            'file.max'      => 'ขนาดไฟล์ต้องไม่เกิน 10 MB',
        ];
    }

    public function attributes(): array
    {
        return [
            'file' => 'ไฟล์นำเข้าข้อมูลบุคลากร',
        ];
    }
}