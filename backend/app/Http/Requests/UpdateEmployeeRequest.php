<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'citizen_id' => [
                'required',
                'string',
                'digits:13',
                Rule::unique('employees', 'citizen_id')->ignore($this->route('employee')),
            ],
        ];
    }
}