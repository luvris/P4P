<?php

namespace App\Http\Requests;

use App\Models\TravelExpenseClaim;
use App\Support\ThaiFiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * บันทึกใบเบิกค่าใช้จ่ายเดินทางไปราชการ (ร่าง หรือ ยืนยัน)
 */
class StoreTravelExpenseClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fiscal_year'       => ['required', 'integer', 'min:' . ThaiFiscalYear::MIN, 'max:' . ThaiFiscalYear::MAX],
            'claim_period'      => ['required', 'date'],
            // เลือกได้เฉพาะประเภทที่ระบบกำหนด (เดินทางไปราชการ / เดินทางไปราชการโดยฝึกอบรม)
            'expense_category'  => ['required', 'string', Rule::in(TravelExpenseClaim::CATEGORIES)],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'note'              => ['nullable', 'string', 'max:2000'],
            'status'            => ['nullable', 'in:' . TravelExpenseClaim::STATUS_DRAFT . ',' . TravelExpenseClaim::STATUS_CONFIRMED],

            'items'                          => ['array'],
            'items.*.employee_id'            => ['nullable', 'integer', 'exists:employees,id'],
            'items.*.first_name'             => ['required', 'string', 'max:255'],
            'items.*.last_name'              => ['required', 'string', 'max:255'],
            'items.*.position_name'          => ['nullable', 'string', 'max:255'],
            'items.*.allowance_amount'       => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.accommodation_amount'   => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.transportation_amount'  => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.other_amount'           => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fiscalYear = (int) $this->input('fiscal_year');
            $period = $this->input('claim_period');

            // เดือนที่เบิกต้องอยู่ในช่วง 1 ต.ค. ถึง 30 ก.ย. ของปีงบที่เลือก
            if ($fiscalYear && $period) {
                try {
                    $date = CarbonImmutable::parse($period);
                    if (! ThaiFiscalYear::contains($fiscalYear, $date)) {
                        $validator->errors()->add(
                            'claim_period',
                            "เดือนที่เบิกต้องอยู่ในปีงบประมาณ {$fiscalYear} (1 ต.ค. " . ($fiscalYear - 1) . " ถึง 30 ก.ย. {$fiscalYear})"
                        );
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('claim_period', 'รูปแบบเดือนที่เบิกไม่ถูกต้อง');
                }
            }

            $items = $this->input('items', []);

            // ยืนยันเอกสารต้องมีผู้เบิกอย่างน้อย 1 คน
            if ($this->input('status') === TravelExpenseClaim::STATUS_CONFIRMED && count($items) === 0) {
                $validator->errors()->add('items', 'ต้องมีรายการผู้เบิกอย่างน้อย 1 รายการก่อนยืนยันเอกสาร');
            }

            // ห้ามบุคลากรซ้ำในเอกสารเดียวกัน
            $employeeIds = array_filter(array_column($items, 'employee_id'));
            if (count($employeeIds) !== count(array_unique($employeeIds))) {
                $validator->errors()->add('items', 'มีบุคลากรซ้ำกันในเอกสารนี้');
            }
        });
    }

    public function messages(): array
    {
        return [
            'fiscal_year.required'      => 'ไม่พบปีงบประมาณ กรุณาเลือกปีงบประมาณที่แถบด้านบน',
            'claim_period.required'     => 'กรุณาเลือกเดือนที่เบิก',
            'claim_period.date'         => 'รูปแบบเดือนที่เบิกไม่ถูกต้อง',
            'expense_category.required' => 'กรุณาเลือกประเภทค่าใช้จ่าย',
            'expense_category.in'       => 'ประเภทค่าใช้จ่ายต้องเป็น "เดินทางไปราชการ" หรือ "เดินทางไปราชการโดยฝึกอบรม"',
            'items.*.first_name.required' => 'กรุณาระบุชื่อผู้เบิก',
            'items.*.last_name.required'  => 'กรุณาระบุนามสกุลผู้เบิก',
            'items.*.allowance_amount.numeric'      => 'ค่าเบี้ยเลี้ยงต้องเป็นตัวเลข',
            'items.*.allowance_amount.min'          => 'ค่าเบี้ยเลี้ยงต้องไม่ติดลบ',
            'items.*.accommodation_amount.numeric'  => 'ค่าที่พักต้องเป็นตัวเลข',
            'items.*.accommodation_amount.min'      => 'ค่าที่พักต้องไม่ติดลบ',
            'items.*.transportation_amount.numeric' => 'ค่าพาหนะต้องเป็นตัวเลข',
            'items.*.transportation_amount.min'     => 'ค่าพาหนะต้องไม่ติดลบ',
            'items.*.other_amount.numeric'          => 'ค่าใช้จ่ายอื่นต้องเป็นตัวเลข',
            'items.*.other_amount.min'              => 'ค่าใช้จ่ายอื่นต้องไม่ติดลบ',
        ];
    }
}
