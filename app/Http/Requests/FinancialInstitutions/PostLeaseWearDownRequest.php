<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  PostLeaseWearDownRequest
//
//  Validates LeasingController::postWearDown() — posting one period
//  of lease-asset wear-down (depreciation / ROU amortisation).
//
//  AUDIT M5 FIX (Sept 2026): the action had no validation. It now
//  accepts an optional for_date (defaults to today) so a missed
//  period can be posted with its correct date instead of today's.
//  Must be a real date, not in the future. The service adds the
//  business checks: not before the lease start date, and never two
//  wear-downs inside the same installment period.
//
//  "Today" is Cairo's date, not the server's UTC date (audit M40).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PostLeaseWearDownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'for_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . now('Africa/Cairo')->toDateString()],
        ];
    }

    public function messages(): array
    {
        return [
            'for_date.before_or_equal' => 'The wear-down date cannot be in the future.',
        ];
    }
}
