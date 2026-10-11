<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class PayrollRunManualAmountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_amount'                        => ['required', 'numeric', 'min:0'],
            'social_insurance_employee_amount'  => ['required', 'numeric', 'min:0'],
            'social_insurance_employer_amount'  => ['required', 'numeric', 'min:0'],
            'advance_deduction_amount'           => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
