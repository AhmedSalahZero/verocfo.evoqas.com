<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class PayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_year'  => ['required', 'integer', 'min:2020', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'run_date'     => ['required', 'date'],
            // M-36: regular (default) | bonus | final_settlement. Extra runs name the employees they cover.
            'run_type'      => ['nullable', 'in:regular,bonus,final_settlement'],
            'partner_ids'   => ['required_if:run_type,bonus,final_settlement', 'nullable', 'array', 'min:1'],
            'partner_ids.*' => ['integer'],
            'label'         => ['nullable', 'string', 'max:120'],
        ];
    }
}
