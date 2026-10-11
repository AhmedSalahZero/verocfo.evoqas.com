<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanManpowerTitleRequest
//
//  Adding a title that doesn't exist in the real team yet. Existing
//  titles are seeded automatically (PlanManpowerService::
//  snapshotExistingTeam()), never entered through this request.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanManpowerTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_title'            => ['required', 'string', 'max:150'],
            'expense_category'     => ['required', 'in:sales,marketing,general_admin,cost_of_service,manufacturing'],
            'avg_current_salary'   => ['required', 'numeric', 'min:0'],
        ];
    }
}
